<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationType;
use App\Models\User;
use Database\Seeders\OrganizationTypeSeeder;
use Database\Seeders\TerritorySeeder;
use Tests\TestCase;

/**
 * Arbre de test :
 *
 *   Police nationale (institution)
 *   └── Direction régionale de Dakar (intermediate)
 *       ├── Commissariat A (structure)
 *       └── Commissariat B (structure)
 *   Gendarmerie nationale (institution)
 *   └── Brigade Z (structure)
 */
abstract class OrganizationTestCase extends TestCase
{
    protected Organization $police;
    protected Organization $direction;
    protected Organization $commissariatA;
    protected Organization $commissariatB;
    protected Organization $gendarmerie;
    protected Organization $brigadeZ;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OrganizationTypeSeeder::class, TerritorySeeder::class]);

        $this->police        = $this->makeOrg('Police nationale', 'police_nationale');
        $this->direction     = $this->makeOrg('Direction régionale de Dakar', 'direction_regionale', $this->police);
        $this->commissariatA = $this->makeOrg('Commissariat A', 'commissariat', $this->direction);
        $this->commissariatB = $this->makeOrg('Commissariat B', 'commissariat', $this->direction);
        $this->gendarmerie   = $this->makeOrg('Gendarmerie nationale', 'gendarmerie_nationale');
        $this->brigadeZ      = $this->makeOrg('Brigade Z', 'brigade', $this->gendarmerie);
    }

    protected function makeOrg(string $name, string $type, ?Organization $parent = null): Organization
    {
        return Organization::create([
            'name'                 => $name,
            'organization_type_id' => OrganizationType::where('code', $type)->value('id'),
            'parent_id'            => $parent?->id,
        ])->fresh();
    }

    protected function member(Organization $org, string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['account_type' => User::TYPE_AGENT] + $attributes);

        OrganizationMembership::create([
            'user_id'         => $user->id,
            'organization_id' => $org->id,
            'role'            => $role,
            'is_primary'      => true,
        ]);

        return $user;
    }

    protected function institutionAdmin(Organization $org): User
    {
        return $this->member($org, OrganizationMembership::ROLE_INSTITUTION_ADMIN);
    }

    protected function manager(Organization $org): User
    {
        return $this->member($org, OrganizationMembership::ROLE_STRUCTURE_MANAGER);
    }

    protected function agent(Organization $org): User
    {
        return $this->member($org, OrganizationMembership::ROLE_AGENT);
    }

    protected function uuidsOf($response): array
    {
        return collect($response->json('data'))->pluck('uuid')->sort()->values()->all();
    }

    protected function sorted(Organization ...$orgs): array
    {
        return collect($orgs)->pluck('uuid')->sort()->values()->all();
    }
}
