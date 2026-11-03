<?php

namespace App\Services\Declarations;

use App\Models\CitizenProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Citizens\CitizenAccountService;
use App\Services\SmsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CitizenProfileService
{
    /**
     * Crée le profil d'une victime au guichet.
     * - Si un compte citoyen existe avec le même téléphone ET le même nom, le profil y est rattaché.
     * - Sinon, avec un email, son compte DocTrack est créé et un lien d'activation lui est envoyé.
     * Dans les deux cas, la personne voit ses déclarations dans son espace.
     */
    public function createAtCounter(array $data, User $agent, ?Organization $organization): CitizenProfile
    {
        return DB::transaction(fn () => $this->doCreateAtCounter($data, $agent, $organization));
    }

    private function doCreateAtCounter(array $data, User $agent, ?Organization $organization): CitizenProfile
    {
        $profile = new CitizenProfile([
            'first_name'                 => trim($data['first_name']),
            'last_name'                  => trim($data['last_name']),
            'birth_date'                 => $data['birth_date'] ?? null,
            'email'                      => $data['email'] ?? null,
            'created_by_user_id'         => $agent->id,
            'created_by_organization_id' => $organization?->id,
        ]);
        $profile->setPhone(SmsService::normalizeSenegalPhone($data['phone']) ?? $data['phone']);

        if ($account = $this->matchingCitizenAccount($data['phone'], $data['last_name'])) {
            $profile->user_id = $account->id;
        }

        $profile->save();

        if (! $profile->user_id && ! empty($data['email'])) {
            app(CitizenAccountService::class)->createForProfile($profile, $data['email'], $agent, $organization);
            $profile->refresh();
        }

        return $profile;
    }

    /**
     * Recherche d'une personne au guichet, sur TOUTE la plateforme (pas seulement les
     * personnes déjà venues dans la structure) :
     *  - fiches déjà connues (déclarations en ligne ou au guichet) ;
     *  - comptes citoyens inscrits sur DocTrack sans fiche : leur fiche est créée à la volée,
     *    rattachée à leur compte, pour pouvoir enregistrer la déclaration.
     *
     * Critères étroits (anti-fouille) : téléphone exact, ou nom + prénom exacts
     * (date de naissance facultative : elle n'est pas demandée à l'inscription).
     *
     * @param array{phone?: ?string, last_name?: ?string, first_name?: ?string, birth_date?: ?string} $criteria
     * @return Collection<int, CitizenProfile>
     */
    public function search(array $criteria, int $limit = 10): Collection
    {
        $profiles = collect();
        $accounts = collect();

        if (! empty($criteria['phone'])) {
            $normalized = SmsService::normalizeSenegalPhone($criteria['phone']);
            $profiles   = CitizenProfile::wherePhone($normalized ?? $criteria['phone'])->limit($limit)->get();

            $digits = $normalized ?? preg_replace('/\D+/', '', (string) $criteria['phone']);
            if (strlen($digits) >= 4) {
                $accounts = $this->citizenAccounts()
                    ->where('Phone', 'like', '%' . substr($digits, -4))
                    ->limit(200)
                    ->get()
                    ->filter(fn (User $u) => (SmsService::normalizeSenegalPhone($u->Phone) ?? preg_replace('/\D+/', '', (string) $u->Phone)) === $digits);
            }
        } else {
            $last  = Str::lower(trim((string) ($criteria['last_name'] ?? '')));
            $first = Str::lower(trim((string) ($criteria['first_name'] ?? '')));
            $birth = $criteria['birth_date'] ?? null;

            $profiles = CitizenProfile::query()
                ->whereRaw('LOWER(last_name) = ?', [$last])
                ->whereRaw('LOWER(first_name) = ?', [$first])
                // Date facultative : si elle est donnée, on écarte seulement les fiches qui en ont une autre.
                ->when($birth, fn ($q) => $q->where(fn ($w) => $w->whereDate('birth_date', $birth)->orWhereNull('birth_date')))
                ->limit($limit)
                ->get();

            $accounts = $this->citizenAccounts()
                ->whereRaw('LOWER(LastName) = ?', [$last])
                ->whereRaw('LOWER(FirstName) = ?', [$first])
                ->limit($limit)
                ->get();
        }

        // Comptes inscrits : leur fiche (existante ou créée à la volée, liée au compte).
        $fromAccounts = $accounts->map(fn (User $u) => $u->citizenProfile ?? $this->forAccount($u));

        return $profiles->concat($fromAccounts)
            ->filter()
            ->unique('id')
            ->take($limit)
            ->values();
    }

    /** Comptes de citoyens (les agents et administrateurs ne sont pas des victimes à rechercher). */
    private function citizenAccounts()
    {
        return User::query()
            ->with('citizenProfile')
            ->where(fn ($q) => $q->whereNull('account_type')->orWhere('account_type', User::TYPE_CITIZEN));
    }

    /** Profils ayant ce téléphone et ce nom (anti-doublon). */
    public function findDuplicate(string $phone, string $lastName): ?CitizenProfile
    {
        return CitizenProfile::wherePhone(SmsService::normalizeSenegalPhone($phone) ?? $phone)
            ->get()
            ->first(fn ($p) => Str::lower(Str::ascii($p->last_name)) === Str::lower(Str::ascii(trim($lastName))));
    }

    /** Profil lié au compte d'un citoyen (créé à la première déclaration en ligne). */
    public function forAccount(User $user): CitizenProfile
    {
        $profile = CitizenProfile::firstOrNew(['user_id' => $user->id]);

        if (! $profile->exists) {
            $profile->fill([
                'first_name' => $user->FirstName,
                'last_name'  => $user->LastName,
                'email'      => $user->email,
            ]);
            $profile->setPhone(SmsService::normalizeSenegalPhone($user->Phone) ?? $user->Phone);
            $profile->save();
        }

        return $profile;
    }

    private function matchingCitizenAccount(string $phone, string $lastName): ?User
    {
        $normalized = SmsService::normalizeSenegalPhone($phone);
        if (! $normalized) {
            return null;
        }

        $lastDigits = substr($normalized, -9);

        return User::where('account_type', User::TYPE_CITIZEN)
            ->where('Phone', 'like', '%' . substr($lastDigits, -4))
            ->whereDoesntHave('citizenProfile')
            ->get()
            ->first(fn (User $u) => SmsService::normalizeSenegalPhone($u->Phone) === $normalized
                && Str::lower(Str::ascii($u->LastName)) === Str::lower(Str::ascii(trim($lastName))));
    }
}
