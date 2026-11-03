<?php

namespace Tests\Feature\Declarations;

use App\Models\CitizenProfile;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Recherche d'une personne au guichet : toute personne inscrite sur DocTrack est trouvée,
 * quelle que soit la structure, même si elle n'a encore jamais déclaré de perte.
 */
class CitizenSearchTest extends OrganizationTestCase
{
    public function test_registered_citizen_without_profile_is_found_by_phone(): void
    {
        $account = $this->citizen(['FirstName' => 'Moussa', 'LastName' => 'Diop', 'Phone' => '+221770000002']);
        $agent   = $this->agent($this->commissariatA);

        $this->assertSame(0, CitizenProfile::count());

        $this->asUser($agent)->getJson('/api/v2/citizens?phone=' . urlencode('77 000 00 02'))->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.first_name', 'Moussa')
            ->assertJsonPath('0.has_account', true);

        // Fiche créée et rattachée à son compte : pas de doublon à la recherche suivante.
        $this->assertSame($account->id, CitizenProfile::sole()->user_id);
        $this->asUser($this->agent($this->brigadeZ))->getJson('/api/v2/citizens?phone=%2B221770000002')->assertOk()->assertJsonCount(1);
        $this->assertSame(1, CitizenProfile::count());
    }

    public function test_identity_search_without_birth_date(): void
    {
        $this->citizen(['FirstName' => 'Moussa', 'LastName' => 'Diop', 'Phone' => '+221770000002']);
        $agent = $this->agent($this->commissariatA);

        $this->asUser($agent)->getJson('/api/v2/citizens?last_name=diop&first_name=MOUSSA')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.last_name', 'Diop');

        // Nom seul : toujours refusé (pas de fouille par nom).
        $this->asUser($agent)->getJson('/api/v2/citizens?last_name=Diop')->assertUnprocessable();
    }

    public function test_agents_and_admins_are_not_returned(): void
    {
        $this->agent($this->commissariatB)->forceFill(['FirstName' => 'Moussa', 'LastName' => 'Diop', 'Phone' => '+221770000003'])->save();

        $this->asUser($this->agent($this->commissariatA))->getJson('/api/v2/citizens?phone=770000003')->assertOk()->assertJsonCount(0);
    }
}
