<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationType;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * - Toujours : les deux institutions racines.
 * - Hors production : structures pilotes et comptes agents de démonstration.
 */
class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $police      = $this->org('POLICE', 'Police nationale', 'police_nationale');
        $gendarmerie = $this->org('GENDARMERIE', 'Gendarmerie nationale', 'gendarmerie_nationale');

        if (app()->environment('production')) {
            return;
        }

        $dieuppeul  = $this->org('PN-DKR-DIEUPPEUL', 'Commissariat d\'arrondissement de Dieuppeul', 'commissariat', $police, 'SN-DK-DAKAR');
        $ziguinchor = $this->org('GN-ZIG-BRIGADE', 'Brigade de gendarmerie de Ziguinchor', 'brigade', $gendarmerie, 'SN-ZG-ZIGUIN');

        $this->agent('admin.police@doctrack.local', 'Point focal', 'Police', $police, OrganizationMembership::ROLE_INSTITUTION_ADMIN);
        $this->agent('chef.dieuppeul@doctrack.local', 'Responsable', 'Dieuppeul', $dieuppeul, OrganizationMembership::ROLE_STRUCTURE_MANAGER);
        $this->agent('agent.dieuppeul@doctrack.local', 'Agent', 'Dieuppeul', $dieuppeul, OrganizationMembership::ROLE_AGENT);
        $this->agent('agent.ziguinchor@doctrack.local', 'Agent', 'Ziguinchor', $ziguinchor, OrganizationMembership::ROLE_AGENT);
    }

    private function org(string $code, string $name, string $type, ?Organization $parent = null, ?string $territory = null): Organization
    {
        return Organization::firstOrCreate(['code' => $code], [
            'name'                 => $name,
            'organization_type_id' => OrganizationType::where('code', $type)->value('id'),
            'parent_id'            => $parent?->id,
            'territory_id'         => $territory ? Territory::where('code', $territory)->value('id') : null,
        ]);
    }

    private function agent(string $email, string $first, string $last, Organization $org, string $role): void
    {
        if (User::where('email', $email)->exists()) {
            return;
        }

        $password = env('SEED_DEMO_PASSWORD') ?: Str::password(16, symbols: false);

        $user = User::create([
            'FirstName'         => $first,
            'LastName'          => $last,
            'Adress'            => $org->name,
            'Phone'             => '+22170' . random_int(1000000, 9999999),
            'email'             => $email,
            'password'          => Hash::make($password),
            'account_type'      => User::TYPE_AGENT,
            'email_verified_at' => now(),
        ]);

        OrganizationMembership::create([
            'user_id'         => $user->id,
            'organization_id' => $org->id,
            'role'            => $role,
            'is_primary'      => true,
        ]);

        if (! env('SEED_DEMO_PASSWORD') && $this->command) {
            $this->command->warn("[{$role}] {$email} — mot de passe généré : {$password}");
        }
    }
}
