<?php

namespace Tests\Feature\Organizations;

use App\Models\OrganizationType;

class OrganizationAccessTest extends OrganizationTestCase
{
    public function test_materialized_paths_are_computed(): void
    {
        $this->assertSame("/{$this->police->id}/", $this->police->path);
        $this->assertSame("/{$this->police->id}/{$this->direction->id}/{$this->commissariatA->id}/", $this->commissariatA->path);
        $this->assertSame(2, $this->commissariatA->depth);
        $this->assertTrue($this->commissariatA->isDescendantOf($this->police));
        $this->assertFalse($this->brigadeZ->isDescendantOf($this->police));
        $this->assertSame([$this->police->id, $this->direction->id], $this->commissariatA->ancestorIds());
        $this->assertSame($this->police->id, $this->commissariatA->institution()->id);
        $this->assertSame($this->police->id, $this->police->institution()->id);
    }

    public function test_each_level_only_sees_its_own_perimeter(): void
    {
        $url = '/api/v2/organizations';

        $this->assertSame(
            $this->sorted($this->police, $this->direction, $this->commissariatA, $this->commissariatB),
            $this->uuidsOf($this->asUser($this->institutionAdmin($this->police))->getJson($url)->assertOk())
        );

        $this->assertSame(
            $this->sorted($this->direction, $this->commissariatA, $this->commissariatB),
            $this->uuidsOf($this->asUser($this->institutionAdmin($this->direction))->getJson($url))
        );

        $this->assertSame([$this->commissariatA->uuid], $this->uuidsOf($this->asUser($this->manager($this->commissariatA))->getJson($url)));
        $this->assertSame([$this->commissariatA->uuid], $this->uuidsOf($this->asUser($this->agent($this->commissariatA))->getJson($url)));

        $this->assertCount(6, $this->asUser($this->admin())->getJson($url)->json('data'));
    }

    public function test_citizen_has_no_access_to_the_institutional_space(): void
    {
        $this->asUser($this->citizen())->getJson('/api/v2/organizations')->assertForbidden();
        $this->asUser($this->citizen())->getJson('/api/v2/organizations/' . $this->commissariatA->uuid)->assertForbidden();
    }

    public function test_no_access_to_another_structure_or_institution(): void
    {
        $agentA = $this->agent($this->commissariatA);
        $this->asUser($agentA)->getJson('/api/v2/organizations/' . $this->commissariatB->uuid)->assertForbidden();

        $policeAdmin = $this->institutionAdmin($this->police);
        $this->asUser($policeAdmin)->getJson('/api/v2/organizations/' . $this->brigadeZ->uuid)->assertForbidden();
        $this->asUser($policeAdmin)->getJson('/api/v2/organizations/' . $this->commissariatB->uuid)->assertOk()
            ->assertJsonPath('my_role', 'institution_admin');
    }

    public function test_only_super_admin_creates_an_institution(): void
    {
        $payload = ['organization_type' => 'police_nationale', 'name' => 'Police municipale'];

        $this->asUser($this->institutionAdmin($this->police))->postJson('/api/v2/organizations', $payload)->assertForbidden();
        $this->asUser($this->admin())->postJson('/api/v2/organizations', $payload)->assertCreated()
            ->assertJsonPath('type.level', OrganizationType::LEVEL_INSTITUTION);
    }

    public function test_institution_admin_creates_structures_in_his_subtree_only(): void
    {
        $admin = $this->institutionAdmin($this->police);

        $response = $this->asUser($admin)->postJson('/api/v2/organizations', [
            'organization_type' => 'commissariat',
            'parent_uuid'       => $this->direction->uuid,
            'name'              => 'Commissariat de Dieuppeul',
            'territory_code'    => 'SN-DK-DAKAR',
        ])->assertCreated()
            ->assertJsonPath('parent.uuid', $this->direction->uuid)
            ->assertJsonPath('territory.name', 'Dakar');

        $this->assertSame(2, $response->json('depth'));

        $this->asUser($admin)->postJson('/api/v2/organizations', [
            'organization_type' => 'brigade',
            'parent_uuid'       => $this->gendarmerie->uuid,
            'name'              => 'Brigade X',
        ])->assertForbidden();
    }

    public function test_structure_manager_cannot_create_organizations(): void
    {
        $this->asUser($this->manager($this->commissariatA))->postJson('/api/v2/organizations', [
            'organization_type' => 'poste',
            'parent_uuid'       => $this->direction->uuid,
            'name'              => 'Poste',
        ])->assertForbidden();
    }

    public function test_a_structure_cannot_have_children_and_institutions_have_no_parent(): void
    {
        $admin = $this->admin();

        $this->asUser($admin)->postJson('/api/v2/organizations', [
            'organization_type' => 'poste', 'parent_uuid' => $this->commissariatA->uuid, 'name' => 'Sous-poste',
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');

        $this->asUser($admin)->postJson('/api/v2/organizations', [
            'organization_type' => 'commissariat', 'name' => 'Sans parent',
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_uuid');

        $this->asUser($admin)->postJson('/api/v2/organizations', [
            'organization_type' => 'gendarmerie_nationale', 'parent_uuid' => $this->police->uuid, 'name' => 'x',
        ])->assertUnprocessable();
    }

    public function test_manager_updates_his_structure_but_not_another(): void
    {
        $manager = $this->manager($this->commissariatA);

        $this->asUser($manager)->patchJson('/api/v2/organizations/' . $this->commissariatA->uuid, ['phone' => '338000000'])
            ->assertOk()->assertJsonPath('phone', '338000000');

        $this->asUser($manager)->patchJson('/api/v2/organizations/' . $this->commissariatB->uuid, ['phone' => '1'])
            ->assertForbidden();
    }

    public function test_only_super_admin_can_move_an_organization_and_paths_follow(): void
    {
        $this->asUser($this->institutionAdmin($this->police))
            ->patchJson('/api/v2/organizations/' . $this->commissariatA->uuid, ['parent_uuid' => $this->police->uuid])
            ->assertForbidden();

        $admin = $this->admin();

        // La direction et ses commissariats passent sous la Gendarmerie (cas d'école).
        $this->asUser($admin)->patchJson('/api/v2/organizations/' . $this->direction->uuid, ['parent_uuid' => $this->gendarmerie->uuid])
            ->assertOk();

        $this->assertSame("/{$this->gendarmerie->id}/{$this->direction->id}/{$this->commissariatA->id}/", $this->commissariatA->fresh()->path);

        // Cycle interdit.
        $this->asUser($admin)->patchJson('/api/v2/organizations/' . $this->direction->uuid, ['parent_uuid' => $this->direction->uuid])
            ->assertUnprocessable();
    }

    public function test_deactivating_a_structure_cuts_its_agents_off(): void
    {
        $agentB   = $this->agent($this->commissariatB);
        $managerB = $this->manager($this->commissariatB);

        // Le responsable ne peut pas désactiver sa propre structure.
        $this->asUser($managerB)->postJson('/api/v2/organizations/' . $this->commissariatB->uuid . '/deactivate')->assertForbidden();

        $this->asUser($this->institutionAdmin($this->police))
            ->postJson('/api/v2/organizations/' . $this->commissariatB->uuid . '/deactivate')
            ->assertOk()->assertJsonPath('is_active', false);

        $this->asUser($agentB)->getJson('/api/v2/organizations/' . $this->commissariatB->uuid)->assertForbidden();
        $this->asUser($agentB)->getJson('/api/v2/me/organizations')->assertOk()->assertJsonCount(0, 'memberships');
    }

    public function test_agent_with_two_structures_chooses_the_active_one(): void
    {
        $agent = $this->agent($this->commissariatA);
        \App\Models\OrganizationMembership::create([
            'user_id' => $agent->id, 'organization_id' => $this->brigadeZ->id, 'role' => 'agent',
        ]);

        $this->asUser($agent)->getJson('/api/v2/me/organizations')
            ->assertOk()
            ->assertJsonCount(2, 'memberships')
            ->assertJsonPath('active_organization', $this->commissariatA->uuid); // affectation principale

        $this->asUser($agent)->withHeader('X-Organization', $this->brigadeZ->uuid)
            ->getJson('/api/v2/me/organizations')
            ->assertJsonPath('active_organization', $this->brigadeZ->uuid);

        $this->asUser($agent)->withHeader('X-Organization', $this->commissariatB->uuid)
            ->getJson('/api/v2/me/organizations')
            ->assertForbidden();
    }

    public function test_reference_data_endpoints(): void
    {
        $agent = $this->agent($this->commissariatA);

        $this->asUser($agent)->getJson('/api/v2/territories?level=region')->assertOk()->assertJsonCount(14);
        $this->asUser($agent)->getJson('/api/v2/territories?parent=SN-ZG')->assertOk()->assertJsonCount(3);
        $this->asUser($agent)->getJson('/api/v2/organization-types')->assertOk()->assertJsonFragment(['code' => 'brigade']);
    }

    public function test_organization_types_are_managed_by_super_admin_only(): void
    {
        $this->asUser($this->institutionAdmin($this->police))
            ->postJson('/api/v2/organization-types', ['code' => 'poste_frontiere', 'name' => 'Poste frontière', 'level' => 'structure'])
            ->assertForbidden();

        $admin = $this->admin();
        $this->asUser($admin)->postJson('/api/v2/organization-types', ['code' => 'poste_frontiere', 'name' => 'Poste frontière', 'level' => 'structure'])
            ->assertCreated();
        $this->asUser($admin)->deleteJson('/api/v2/organization-types/poste_frontiere')->assertOk();
        $this->asUser($admin)->deleteJson('/api/v2/organization-types/commissariat')->assertStatus(409);
    }
}
