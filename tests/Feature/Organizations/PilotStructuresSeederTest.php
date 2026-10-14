<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PilotStructuresSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TerritorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PilotStructuresSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_structures_and_accounts_are_created_once(): void
    {
        Storage::fake('local');
        putenv('SEED_DEMO_PASSWORD=Essai-Pilote-2026');
        $_ENV['SEED_DEMO_PASSWORD'] = $_SERVER['SEED_DEMO_PASSWORD'] = 'Essai-Pilote-2026';

        $this->seed([RolesAndPermissionsSeeder::class, TerritorySeeder::class, \Database\Seeders\OrganizationTypeSeeder::class, OrganizationSeeder::class]);
        $before = Organization::count();
        $this->seed(PilotStructuresSeeder::class);
        $this->seed(PilotStructuresSeeder::class);      // relançable sans doublon

        $this->assertSame($before + 220, Organization::count());   // 221 lignes, Dieuppeul existait déjà

        $dakar = Organization::where('code', 'SN-POL-001')->firstOrFail();
        $this->assertSame('Commissariat Central de Dakar', $dakar->name);
        $this->assertSame('+221775290023', $dakar->phone);
        $this->assertSame('POLICE', $dakar->parent->code);
        $this->assertSame('Dakar', $dakar->territory->name);
        $this->assertSame('Guédiawaye', Organization::where('code', 'SN-POL-002')->first()->territory->name);
        $this->assertSame('+221775290074', Organization::where('code', 'PN-DKR-DIEUPPEUL')->first()->phone);
        $this->assertSame(0, Organization::whereNull('territory_id')->whereNotIn('code', ['POLICE', 'GENDARMERIE'])->count());

        $chef = User::where('email', 'chef.pol001@doctrack.local')->firstOrFail();
        $this->assertTrue(Hash::check('Essai-Pilote-2026', $chef->password));
        $this->assertSame(OrganizationMembership::ROLE_STRUCTURE_MANAGER, $chef->memberships()->first()->role);
        $this->assertSame(221 + 8, User::where('email', 'like', '%.pol0%')->orWhere('email', 'like', '%.gen%')->count());
        $this->assertTrue(User::where('email', 'agent2.gen202@doctrack.local')->exists());
        Storage::disk('local')->assertExists('seed/comptes_structures.csv');

        $token = $this->postJson('/api/login', ['email' => 'agent1.pol001@doctrack.local', 'password' => 'Essai-Pilote-2026']);
        $token->assertOk();
    }

    public function test_names_types_and_phones(): void
    {
        $this->assertSame("Commissariat d'arrondissement de Dieuppeul", PilotStructuresSeeder::displayName('Commissariat d’arrondissement de DIEUPPEUL'));
        $this->assertSame('Brigade Port de Peche', PilotStructuresSeeder::displayName('Brigade PORT DE PECHE'));
        $this->assertSame('poste_gendarmerie', PilotStructuresSeeder::typeOf(['corps' => 'gendarmerie', 'name' => 'Poste de X']));
        $this->assertSame('compagnie', PilotStructuresSeeder::typeOf(['corps' => 'gendarmerie', 'name' => 'Commandant Compagnie KOLDA']));
        $this->assertNull(PilotStructuresSeeder::phone('77 529 72'));
        $this->assertNull(PilotStructuresSeeder::phone(null));
    }

    public function test_structures_filtered_by_institution_and_region(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, TerritorySeeder::class, \Database\Seeders\OrganizationTypeSeeder::class, OrganizationSeeder::class, \Database\Seeders\UserSeeder::class]);
        $this->seed(PilotStructuresSeeder::class);
        $admin  = User::role('super_admin', 'api')->firstOrFail();
        $police = Organization::where('code', 'POLICE')->firstOrFail();

        $r = $this->asUser($admin)->getJson('/api/v2/organizations?institution=' . $police->uuid)->assertOk();
        $this->assertSame(58, $r->json('meta.total'));            // la Police nationale et ses 57 structures
        $this->assertSame('POLICE', $r->json('data.0.code'));

        $zig = $this->asUser($admin)->getJson('/api/v2/organizations?region=SN-ZG&institution=' . $police->uuid)->assertOk();
        $this->assertSame(2, $zig->json('meta.total'));
    }
}
