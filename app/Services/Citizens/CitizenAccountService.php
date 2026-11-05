<?php

namespace App\Services\Citizens;

use App\Models\CitizenProfile;
use App\Models\DeclarationDePerte;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CitizenAccountActivationNotification;
use App\Services\Notifications\Notifier;
use App\Services\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Compte DocTrack d'une personne reçue au guichet.
 *
 * - Avec un email : l'agent crée le compte ; la personne reçoit un lien (valable 7 jours) pour choisir
 *   son mot de passe. Ses déclarations, passées et futures, apparaissent dans son espace.
 * - Sans email : la fiche reste sans compte ; si la personne s'inscrit plus tard en ligne avec le même
 *   téléphone, le même nom et le même prénom, sa fiche et ses déclarations sont rattachées à son compte.
 */
class CitizenAccountService
{
    public const ACTIVATION_BROKER = 'activations';

    public function __construct(private Notifier $notifier) {}

    /**
     * Crée (ou retrouve) le compte de la personne et y rattache sa fiche et ses déclarations.
     * $agent est null quand la personne le demande elle-même (« mot de passe oublié » avec l'email
     * donné au guichet).
     */
    public function createForProfile(CitizenProfile $profile, string $email, ?User $agent, ?Organization $structure): User
    {
        if ($profile->user_id) {
            return $profile->user;
        }

        $email    = Str::lower(trim($email));
        $existing = User::where('email', $email)->first();

        if ($existing) {
            // Même personne (même nom) : on rattache. Sinon l'adresse appartient à quelqu'un d'autre.
            if (! $existing->isCitizen() || ! self::sameName($existing->LastName, $profile->last_name)) {
                throw ValidationException::withMessages(['email' => 'Cette adresse email est déjà utilisée par un autre compte.']);
            }
            if ($existing->citizenProfile && $existing->citizenProfile->id !== $profile->id) {
                throw ValidationException::withMessages(['email' => 'Ce compte est déjà lié à une autre fiche citoyen.']);
            }
            $this->attach($profile, $existing);

            return $existing;
        }

        $user = DB::transaction(function () use ($profile, $email, $structure) {
            $user = User::create([
                'FirstName'          => $profile->first_name,
                'LastName'           => $profile->last_name,
                'Adress'             => $structure?->territory?->name ?? 'Non renseignée',
                'Phone'              => SmsService::normalizeSenegalPhone($profile->phone()) ?? (string) $profile->phone(),
                'email'              => $email,
                // Mot de passe inconnu de tous : la personne choisit le sien avec le lien d'activation.
                'password'           => Hash::make(Str::random(48)),
                'qr_code_token'      => Uuid::uuid4()->toString(),
                'qr_code_expires_at' => now()->addMonths((int) config('doctrack.qr_code_validity_months', 12)),
                'account_type'       => User::TYPE_CITIZEN,
            ]);
            $user->assignRole(User::ROLE_CITIZEN);
            $profile->forceFill(['email' => $email])->save();
            $this->attach($profile, $user);

            return $user;
        });

        $this->sendActivation($user, $structure);

        $log = activity()->performedOn($user)
            ->withProperties(['organization_id' => $structure?->id, 'citizen_profile' => $profile->uuid]);
        if ($agent) {
            $log->causedBy($agent);
        }
        $log->log($agent ? 'Compte citoyen créé au guichet' : 'Compte citoyen créé par la personne (lien « mot de passe oublié »)');

        return $user;
    }

    /** Envoie (ou renvoie) le lien d'activation. */
    public function sendActivation(User $user, ?Organization $structure = null): void
    {
        $token = Password::broker(self::ACTIVATION_BROKER)->createToken($user);

        $this->notifier->mail('account.activation', $user, new CitizenAccountActivationNotification($token, $structure?->name),
            ['recipient' => $user, 'organization_id' => $structure?->id]);
    }

    /**
     * Inscription en ligne ou activation : rattache les fiches créées au guichet sans compte
     * (même téléphone, même nom, même prénom).
     */
    public function attachOrphanProfiles(User $user): int
    {
        if (! $user->isCitizen() || ! $user->Phone || $user->citizenProfile()->exists()) {
            return 0;
        }

        $phone = SmsService::normalizeSenegalPhone($user->Phone) ?? $user->Phone;
        $profile = CitizenProfile::wherePhone($phone)->whereNull('user_id')->get()
            ->first(fn (CitizenProfile $p) => self::sameName($p->last_name, $user->LastName) && self::sameName($p->first_name, $user->FirstName));

        if (! $profile) {
            return 0;
        }

        return $this->attach($profile, $user);
    }

    /** Lie la fiche au compte, et ses déclarations à l'espace du citoyen. */
    private function attach(CitizenProfile $profile, User $user): int
    {
        $profile->forceFill(['user_id' => $user->id])->save();

        return DeclarationDePerte::withTrashed()->where('citizen_profile_id', $profile->id)->whereNull('user_id')
            ->update(['user_id' => $user->id]);
    }

    private static function sameName(?string $a, ?string $b): bool
    {
        $norm = fn ($v) => preg_replace('/[^a-z]/', '', Str::lower(Str::ascii((string) $v)));

        return $norm($a) !== '' && $norm($a) === $norm($b);
    }
}
