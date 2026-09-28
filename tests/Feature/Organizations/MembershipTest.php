<?php

namespace Tests\Feature\Organizations;

use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

class MembershipTest extends OrganizationTestCase
{
    private function newAgentPayload(array $overrides = []): array
    {
        return array_merge([
            'email'     => 'moussa.fall@police.sn',
            'FirstName' => 'Moussa',
            'LastName'  => 'Fall',
            'Phone'     => '+221771112233',
            'role'      => 'agent',
            'matricule' => 'PN-12345',
        ], $overrides);
    }

    public function test_manager_creates_a_named_agent_account_with_an_invitation(): void
    {
        Notification::fake();
        $manager = $this->manager($this->commissariatA);

        $this->asUser($manager)
            ->postJson("/api/v2/organizations/{$this->commissariatA->uuid}/members", $this->newAgentPayload())
            ->assertCreated()
            ->assertJsonPath('role', 'agent')
            ->assertJsonPath('matricule', 'PN-12345')
            ->assertJsonPath('is_primary', true)
            ->assertJsonPath('invitation_sent', true);

        $agent = User::where('email', 'moussa.fall@police.sn')->first();
        $this->assertSame(User::TYPE_AGENT, $agent->account_type);
        $this->assertFalse($agent->hasRole(User::ROLE_CITIZEN));
        Notification::assertSentTo($agent, ResetPassword::class);

        $membership = $agent->memberships()->first();
        $this->assertSame($manager->id, $membership->created_by);
    }

    public function test_every_agent_action_is_traced_with_author_and_structure(): void
    {
        Notification::fake();
        $manager = $this->manager($this->commissariatA);

        $this->asUser($manager)
            ->postJson("/api/v2/organizations/{$this->commissariatA->uuid}/members", $this->newAgentPayload())
            ->assertCreated();

        $log = Activity::where('subject_type', OrganizationMembership::class)->latest('id')->first();
        $this->assertSame($manager->id, $log->causer_id);
        $this->assertSame($this->commissariatA->id, $log->organization_id);
        $this->assertNotNull($log->ip_address);
    }

    public function test_manager_can_only_create_plain_agents_in_his_own_structure(): void
    {
        $manager = $this->manager($this->commissariatA);

        $this->asUser($manager)
            ->postJson("/api/v2/organizations/{$this->commissariatA->uuid}/members", $this->newAgentPayload(['role' => 'structure_manager']))
            ->assertForbidden();

        $this->asUser($manager)
            ->postJson("/api/v2/organizations/{$this->commissariatB->uuid}/members", $this->newAgentPayload())
            ->assertForbidden();
    }

    public function test_agent_cannot_manage_or_list_members(): void
    {
        $agent = $this->agent($this->commissariatA);

        $this->asUser($agent)->getJson("/api/v2/organizations/{$this->commissariatA->uuid}/members")->assertForbidden();
        $this->asUser($agent)
            ->postJson("/api/v2/organizations/{$this->commissariatA->uuid}/members", $this->newAgentPayload())
            ->assertForbidden();
    }

    public function test_institution_admin_appoints_managers_in_his_subtree_with_level_rules(): void
    {
        Notification::fake();
        $admin = $this->institutionAdmin($this->police);

        $this->asUser($admin)
            ->postJson("/api/v2/organizations/{$this->commissariatB->uuid}/members", $this->newAgentPayload(['role' => 'structure_manager']))
            ->assertCreated();

        // Un responsable de structure ne se rattache pas à une direction.
        $this->asUser($admin)
            ->postJson("/api/v2/organizations/{$this->direction->uuid}/members", $this->newAgentPayload(['email' => 'x@police.sn', 'role' => 'structure_manager']))
            ->assertUnprocessable()->assertJsonValidationErrors('role');

        // Hors de son institution : refusé.
        $this->asUser($admin)
            ->postJson("/api/v2/organizations/{$this->brigadeZ->uuid}/members", $this->newAgentPayload(['email' => 'y@police.sn']))
            ->assertForbidden();
    }

    public function test_a_citizen_account_cannot_be_turned_into_an_agent(): void
    {
        $citizen = $this->citizen(['email' => 'awa@gmail.com']);

        $this->asUser($this->manager($this->commissariatA))
            ->postJson("/api/v2/organizations/{$this->commissariatA->uuid}/members", $this->newAgentPayload(['email' => $citizen->email]))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_existing_agent_can_be_attached_to_a_second_structure(): void
    {
        $agent = $this->agent($this->commissariatA);

        $this->asUser($this->institutionAdmin($this->police))
            ->postJson("/api/v2/organizations/{$this->commissariatB->uuid}/members", $this->newAgentPayload(['email' => $agent->email]))
            ->assertCreated()
            ->assertJsonPath('invitation_sent', false)
            ->assertJsonPath('is_primary', false);

        $this->assertSame(2, $agent->activeMemberships()->count());
    }

    public function test_members_listing_is_limited_to_the_structure(): void
    {
        $manager = $this->manager($this->commissariatA);
        $this->agent($this->commissariatA);
        $this->agent($this->commissariatB);

        $this->asUser($manager)->getJson("/api/v2/organizations/{$this->commissariatA->uuid}/members")
            ->assertOk()->assertJsonCount(2);
    }

    public function test_ending_the_last_assignment_suspends_the_agent_immediately(): void
    {
        $manager = $this->manager($this->commissariatA);
        $agent   = $this->agent($this->commissariatA);
        $membership = $agent->memberships()->first();

        $this->asUser($manager)->postJson("/api/v2/memberships/{$membership->uuid}/end")
            ->assertOk()->assertJsonPath('is_active', false);

        $this->assertSame(User::STATUS_SUSPENDED, $agent->fresh()->status);
        $this->assertModelExists($membership); // historique conservé

        // Jeton encore valide, mais compte suspendu : refusé partout.
        $this->asUser($agent)->getJson('/api/me')->assertForbidden();
        $this->asUser($agent)->getJson('/api/v2/me/organizations')->assertForbidden();
        $this->postJson('/api/login', ['email' => $agent->email, 'password' => 'password'])->assertForbidden();
    }

    public function test_reassigning_a_suspended_agent_reactivates_him(): void
    {
        $admin = $this->institutionAdmin($this->police);
        $agent = $this->agent($this->commissariatA);
        $this->asUser($admin)->postJson('/api/v2/memberships/' . $agent->memberships()->first()->uuid . '/end')->assertOk();

        $this->asUser($admin)
            ->postJson("/api/v2/organizations/{$this->commissariatA->uuid}/members", $this->newAgentPayload(['email' => $agent->email]))
            ->assertCreated();

        $this->assertSame(User::STATUS_ACTIVE, $agent->fresh()->status);
        $this->assertSame(1, OrganizationMembership::where('user_id', $agent->id)->count()); // même ligne réactivée
    }

    public function test_nobody_manages_his_own_assignment_or_a_peer(): void
    {
        $manager      = $this->manager($this->commissariatA);
        $otherManager = $this->manager($this->commissariatA);

        $this->asUser($manager)->postJson('/api/v2/memberships/' . $manager->memberships()->first()->uuid . '/end')->assertForbidden();
        $this->asUser($manager)->postJson('/api/v2/memberships/' . $otherManager->memberships()->first()->uuid . '/end')->assertForbidden();
        $this->asUser($manager)->patchJson('/api/v2/memberships/' . $otherManager->memberships()->first()->uuid, ['role' => 'agent'])->assertForbidden();
    }

    public function test_role_change_respects_the_actor_level(): void
    {
        $admin   = $this->institutionAdmin($this->police);
        $manager = $this->manager($this->commissariatA);
        $agent   = $this->agent($this->commissariatA);
        $membership = $agent->memberships()->first();

        // Le responsable ne peut pas promouvoir un agent responsable.
        $this->asUser($manager)->patchJson("/api/v2/memberships/{$membership->uuid}", ['role' => 'structure_manager'])->assertForbidden();
        // Il peut modifier le matricule.
        $this->asUser($manager)->patchJson("/api/v2/memberships/{$membership->uuid}", ['matricule' => 'NEW-1'])
            ->assertOk()->assertJsonPath('matricule', 'NEW-1');
        // L'administrateur institutionnel peut promouvoir.
        $this->asUser($admin)->patchJson("/api/v2/memberships/{$membership->uuid}", ['role' => 'structure_manager'])
            ->assertOk()->assertJsonPath('role', 'structure_manager');
    }

    public function test_login_exposes_legacy_role_names_and_assignments(): void
    {
        $this->admin(['email' => 'root@doctrack.sn']);
        $roles = $this->postJson('/api/login', ['email' => 'root@doctrack.sn', 'password' => 'password'])->assertOk()->json('roles');
        $this->assertContains('super_admin', $roles);
        $this->assertContains('Admin', $roles); // compatibilité front V1

        $agent = $this->agent($this->commissariatA);
        $this->postJson('/api/login', ['email' => $agent->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('organizations.0.organization.uuid', $this->commissariatA->uuid)
            ->assertJsonPath('organizations.0.role', 'agent');

        $this->assertNotNull($agent->fresh()->last_login_at);
    }

    public function test_deleting_a_user_anonymizes_instead_of_erasing(): void
    {
        $citizen = $this->citizen(['email' => 'todelete@example.com']);
        \App\Models\DeclarationDePerte::factory()->for($citizen)->create();

        $this->asUser($this->admin())->deleteJson('/api/users/' . $citizen->uuid)->assertOk();

        $trashed = User::withTrashed()->find($citizen->id);
        $this->assertTrue($trashed->trashed());
        $this->assertStringEndsWith('@doctrack.invalid', $trashed->email);
        $this->assertSame(1, \App\Models\DeclarationDePerte::where('user_id', $citizen->id)->count());
    }
}
