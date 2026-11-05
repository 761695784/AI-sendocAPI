<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationType;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Structures de test : commissariats, postes, brigades et compagnies de l'annuaire public ONLPL
 * (database/data/structures_onlpl.json), avec leurs comptes.
 *
 *   php artisan db:seed --class=PilotStructuresSeeder
 *
 * - Chaque structure est rattachée à la Police ou à la Gendarmerie nationale, à sa région
 *   (et à son département quand son nom le contient), avec son téléphone public.
 * - Hors production : un compte « responsable » par structure, et deux agents pour les
 *   structures pilotes (SEED_PILOT_STRUCTURES). Mot de passe commun : SEED_DEMO_PASSWORD.
 * - En production, seulement si SEED_STRUCTURE_ACCOUNTS=true : les mêmes comptes, mais chacun
 *   avec son propre mot de passe aléatoire (jamais de mot de passe commun en ligne). Les
 *   identifiants sont écrits dans storage/app/seed/comptes_structures_production.csv (dossier
 *   privé) : à télécharger, à transmettre à chaque structure, puis à supprimer du serveur.
 * - Relançable sans doublon. La liste des comptes (sans mot de passe) est écrite dans
 *   storage/app/seed/comptes_structures.csv.
 *
 * Données d'annuaire à confirmer avant tout usage réel : adresses, activité, position GPS (non renseignée).
 */
class PilotStructuresSeeder extends Seeder
{
    /** Structures déjà créées par OrganizationSeeder sous un autre code. */
    private const EXISTING = ['SN-POL-008' => 'PN-DKR-DIEUPPEUL'];

    private ?string $passwordHash = null;
    private ?string $generatedPassword = null;
    /** Production : un mot de passe différent par compte. */
    private bool $uniquePasswords = false;
    /** @var array<int, array<int, string>> */
    private array $accounts = [];

    public function run(): void
    {
        $this->call(OrganizationTypeSeeder::class);

        $roots = [
            'police'      => Organization::where('code', 'POLICE')->first(),
            'gendarmerie' => Organization::where('code', 'GENDARMERIE')->first(),
        ];
        if (! $roots['police'] || ! $roots['gendarmerie']) {
            $this->call(OrganizationSeeder::class);
            $roots = [
                'police'      => Organization::where('code', 'POLICE')->firstOrFail(),
                'gendarmerie' => Organization::where('code', 'GENDARMERIE')->firstOrFail(),
            ];
        }

        $types   = OrganizationType::pluck('id', 'code');
        $regions = Territory::where('level', Territory::LEVEL_REGION)->with('children')->get()
            ->keyBy(fn (Territory $t) => self::key($t->name));
        $pilots  = array_filter(array_map('trim', explode(',', (string) env('SEED_PILOT_STRUCTURES', 'SN-POL-001,SN-POL-008,SN-POL-055,SN-GEN-202'))));
        $this->uniquePasswords = app()->environment('production');
        $withAccounts = ! $this->uniquePasswords || filter_var(env('SEED_STRUCTURE_ACCOUNTS', false), FILTER_VALIDATE_BOOLEAN);

        $rows = json_decode(file_get_contents(database_path('data/structures_onlpl.json')), true, flags: JSON_THROW_ON_ERROR);
        $created = 0;

        foreach ($rows as $index => $row) {
            $region = $regions->get(self::key($row['region']));
            $org = Organization::firstOrNew(['code' => self::EXISTING[$row['id']] ?? $row['id']]);
            $created += $org->exists ? 0 : 1;
            $org->fill([
                'name'                 => self::displayName($row['name']),
                'organization_type_id' => $types[self::typeOf($row)],
                'parent_id'            => $roots[$row['corps']]->id,
                'territory_id'         => $org->territory_id ?? self::territoryOf($row, $region)?->id,
                'address'              => $org->address ?? $row['location'],
                'phone'                => self::phone($row['phone']) ?? $org->phone,
            ])->save();

            if ($withAccounts) {
                $slug = strtolower(str_replace(['SN-', '-'], '', $row['id']));           // SN-POL-001 → pol001
                $this->account("chef.{$slug}@doctrack.local", 'Responsable', $org, OrganizationMembership::ROLE_STRUCTURE_MANAGER, $index * 10);
                if (in_array($row['id'], $pilots, true)) {
                    $this->account("agent1.{$slug}@doctrack.local", 'Agent 1', $org, OrganizationMembership::ROLE_AGENT, $index * 10 + 1);
                    $this->account("agent2.{$slug}@doctrack.local", 'Agent 2', $org, OrganizationMembership::ROLE_AGENT, $index * 10 + 2);
                }
            }
        }

        $this->command?->info(count($rows) . " structures ({$created} nouvelles).");

        if ($withAccounts) {
            $header = $this->uniquePasswords ? "email;role;structure;code;region;mot_de_passe\n" : "email;role;structure;code;region\n";
            $file   = $this->uniquePasswords ? 'seed/comptes_structures_production.csv' : 'seed/comptes_structures.csv';
            $csv = $header . implode("\n", array_map(fn ($a) => implode(';', $a), $this->accounts)) . "\n";
            Storage::disk('local')->put($file, "\u{FEFF}" . $csv);
            $this->command?->info(count($this->accounts) . " comptes : storage/app/{$file}");
            if ($this->uniquePasswords) {
                $this->command?->warn('Ce fichier contient les mots de passe des nouveaux comptes : téléchargez-le, puis supprimez-le du serveur.');
            }
            if ($this->generatedPassword) {
                $this->command?->warn("SEED_DEMO_PASSWORD est vide — mot de passe commun généré pour les nouveaux comptes : {$this->generatedPassword}");
            }
        }
    }

    private function account(string $email, string $first, Organization $org, string $role, int $n): void
    {
        $line = [$email, $role, $org->name, $org->code, $org->territory?->name ?? ''];

        if (User::where('email', $email)->exists()) {
            $this->accounts[] = $this->uniquePasswords ? [...$line, '(compte déjà existant)'] : $line;
            return;
        }

        if ($this->uniquePasswords) {
            // Production : mot de passe propre à chaque compte.
            $password = Str::password(14, symbols: false);
            $this->passwordHash = Hash::make($password);
            $line[] = $password;
        } elseif ($this->passwordHash === null) {
            // Hors production : un seul hachage (bcrypt est lent), mot de passe commun.
            $password = env('SEED_DEMO_PASSWORD') ?: ($this->generatedPassword = Str::password(16, symbols: false));
            $this->passwordHash = Hash::make($password);
        }
        $this->accounts[] = $line;

        $user = new User([
            'FirstName'         => $first,
            'LastName'          => Str::limit(preg_replace('/^(Commissariat|Brigade|Poste|Compagnie)\b.*?\b(de |du |des |d\')/iu', '', $org->name) ?: $org->name, 60, ''),
            'Adress'            => $org->name,
            'Phone'             => '+2217090' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),   // numéro fictif
            'email'             => $email,
            'account_type'      => User::TYPE_AGENT,
            'email_verified_at' => now(),
        ]);
        $user->password = $this->passwordHash;
        $user->email_verified_at = now();
        $user->save();

        OrganizationMembership::create([
            'user_id'         => $user->id,
            'organization_id' => $org->id,
            'role'            => $role,
            'is_primary'      => true,
        ]);
    }

    /** « Commissariat Central de DAKAR » → « Commissariat Central de Dakar ». */
    public static function displayName(string $name): string
    {
        $name = str_replace(['’', 'Ubain', 'Térritoriale', 'Territorial '], ["'", 'Urbain', 'Territoriale', 'Territoriale '], Str::ucfirst(trim($name)));

        return preg_replace_callback('/\b\p{Lu}[\p{Lu}\-]+\b/u', fn ($m) => match (true) {
            in_array($m[0], ['AIBD', 'GTER', 'CTA', 'BAD', 'HLM'], true) => $m[0],
            in_array($m[0], ['DE', 'DU', 'DES', 'LA', 'LE'], true)        => Str::lower($m[0]),
            default                                                       => Str::title($m[0]),
        }, $name);
    }

    public static function typeOf(array $row): string
    {
        $name = Str::lower(Str::ascii($row['name']));
        if ($row['corps'] === 'police') {
            return str_starts_with($name, 'poste') ? 'poste' : 'commissariat';
        }

        return match (true) {
            str_starts_with($name, 'poste')                                    => 'poste_gendarmerie',
            (bool) preg_match('/^(compagnie|groupement|commandant)/', $name)   => 'compagnie',
            default                                                            => 'brigade',
        };
    }

    /** Département dont le nom figure dans celui de la structure ou sa localité, sinon la région. */
    private static function territoryOf(array $row, ?Territory $region): ?Territory
    {
        if (! $region) {
            return null;
        }
        $haystack = ' ' . self::key($row['name'] . ' ' . ($row['location'] ?? '')) . ' ';
        $match = $region->children
            ->sortByDesc(fn (Territory $d) => strlen($d->name))
            ->first(fn (Territory $d) => str_contains($haystack, ' ' . self::key($d->name) . ' '));

        return $match ?? $region;
    }

    public static function phone(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        return strlen($digits) === 9 ? '+221' . $digits : null;      // numéro incomplet dans l'annuaire : ignoré
    }

    private static function key(string $text): string
    {
        return trim(preg_replace('/[^A-Z]+/', ' ', Str::upper(Str::ascii($text))));
    }
}
