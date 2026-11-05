<?php

namespace Tests\Feature\Collections;

use App\Models\CollectionRequest;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Territory;
use App\Models\User;
use App\Services\Collections\NearestStructureFinder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Le trouveur publie depuis chez lui ; la structure la plus proche l'appelle
 * et organise le dépôt ou le passage d'un agent.
 *
 * Carte de test :
 *   Commissariat A — Dieuppeul (Dakar)      14.7160, -17.4580
 *   Commissariat B — Plateau (Dakar)        14.6700, -17.4380
 *   Brigade Z      — Ziguinchor             12.5640, -16.2720
 */
class CollectionTest extends OrganizationTestCase
{
    private DocumentType $cni;

    private const NEAR_PLATEAU    = ['latitude' => 14.6720, 'longitude' => -17.4400];
    private const NEAR_DIEUPPEUL  = ['latitude' => 14.7150, 'longitude' => -17.4590];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->cni = DocumentType::factory()->create(['TypeName' => 'Carte nationale d\'identité']);

        $dakar = Territory::where('code', 'SN-DK-DAKAR')->value('id');
        $this->commissariatA->update(['territory_id' => $dakar, 'latitude' => 14.7160, 'longitude' => -17.4580]);
        $this->commissariatB->update(['territory_id' => $dakar, 'latitude' => 14.6700, 'longitude' => -17.4380]);
        $this->brigadeZ->update(['territory_id' => Territory::where('code', 'SN-ZG-ZIGUIN')->value('id'), 'latitude' => 12.5640, 'longitude' => -16.2720]);
    }

    private function publish(?User $finder = null, array $data = [])
    {
        return $this->asUser($finder ?? $this->citizen())->post('/api/documents', array_merge([
            'image'            => UploadedFile::fake()->image('cni.jpg', 600, 400),
            'OwnerFirstName'   => 'Awa',
            'OwnerLastName'    => 'Ndiaye',
            'Location'         => 'Marché Sandaga',
            'document_type_id' => $this->cni->id,
            'contact_consent'  => true,
            'contact_phone'    => '77 555 44 33',
        ], $data), ['Accept' => 'application/json']);
    }

    private function collection(): CollectionRequest
    {
        return CollectionRequest::latest('id')->firstOrFail();
    }

    // ── Affectation ───────────────────────────────────────────

    public function test_publication_is_assigned_to_the_nearest_structure_by_gps(): void
    {
        $finder   = $this->citizen();
        $response = $this->publish($finder, self::NEAR_PLATEAU)->assertCreated()
            ->assertJsonPath('collection.status', 'assigned')
            ->assertJsonPath('collection.structure.name', 'Commissariat B');

        $this->assertStringContainsString('Commissariat B va vous contacter', $response->json('message'));

        $c = $this->collection();
        $this->assertSame($this->commissariatB->id, $c->organization_id);
        $this->assertSame('gps', $c->assignment_reason);
        $this->assertLessThan(1, $c->distance_km);

        // Le trouveur suit sa collecte.
        $this->asUser($finder)->getJson('/api/documents/' . $response->json('document.uuid') . '/collection')
            ->assertOk()->assertJsonPath('collection.structure.name', 'Commissariat B');
        $this->asUser($this->citizen())->getJson('/api/documents/' . $response->json('document.uuid') . '/collection')
            ->assertForbidden();
    }

    public function test_without_gps_the_department_decides(): void
    {
        $this->publish(null, ['territory_code' => 'SN-ZG-ZIGUIN'])->assertCreated();

        $c = $this->collection();
        $this->assertSame($this->brigadeZ->id, $c->organization_id);
        $this->assertSame('department', $c->assignment_reason);
    }

    public function test_without_consent_nothing_is_collected(): void
    {
        $this->publish(null, ['contact_consent' => false] + self::NEAR_PLATEAU)->assertCreated()->assertJsonPath('collection', null);

        $this->assertSame(0, CollectionRequest::count());
    }

    public function test_consent_requires_a_phone_number(): void
    {
        $finder = $this->citizen(['Phone' => '']);

        $this->publish($finder, ['contact_phone' => null])->assertUnprocessable()->assertJsonValidationErrors('contact_phone');
        $this->publish($finder, ['contact_phone' => '77 555 44 33'])->assertCreated();
    }

    public function test_gps_outside_senegal_is_rejected(): void
    {
        $this->publish(null, ['latitude' => 48.85, 'longitude' => 2.35])->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    // ── Espace agent ──────────────────────────────────────────

    public function test_only_the_assigned_structure_sees_the_finder_phone(): void
    {
        $this->publish(null, self::NEAR_PLATEAU);
        $c = $this->collection();

        $agentB = $this->agent($this->commissariatB);
        $list   = $this->asUser($agentB)->getJson('/api/v2/collections')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('••••••433', $list->json('data.0.finder.phone'));

        $this->asUser($agentB)->getJson('/api/v2/collections/' . $c->uuid)->assertOk()
            ->assertJsonPath('finder.phone', '775554433')
            ->assertJsonPath('document.last_name', 'Ndiaye');
        $this->assertTrue(Activity::where('description', 'Consultation du contact du trouveur')->where('causer_id', $agentB->id)->exists());

        // Autres structures, citoyens : rien.
        $this->asUser($this->agent($this->commissariatA))->getJson('/api/v2/collections')->assertJsonCount(0, 'data');
        $this->asUser($this->agent($this->commissariatA))->getJson('/api/v2/collections/' . $c->uuid)->assertForbidden();
        $this->asUser($this->citizen())->getJson('/api/v2/collections')->assertForbidden();

        // Le responsable de l'institution supervise.
        $this->asUser($this->institutionAdmin($this->police))->getJson('/api/v2/collections')->assertJsonCount(1, 'data');
    }

    public function test_full_workflow_until_the_agent_brings_the_document_back(): void
    {
        $docUuid = $this->publish(null, self::NEAR_PLATEAU)->json('document.uuid');
        $c       = $this->collection();
        $agentB  = $this->agent($this->commissariatB);
        $url     = '/api/v2/collections/' . $c->uuid;

        $this->asUser($agentB)->postJson("$url/contact", ['outcome' => 'no_answer'])->assertOk()
            ->assertJsonPath('status', 'assigned')->assertJsonPath('contact_attempts', 1);
        $this->asUser($agentB)->postJson("$url/contact", ['outcome' => 'reached', 'note' => 'Disponible demain matin'])->assertOk()
            ->assertJsonPath('status', 'contacted')->assertJsonPath('contact_attempts', 2);

        $this->asUser($agentB)->postJson("$url/plan", [
            'method'        => 'agent_pickup',
            'scheduled_at'  => now()->addDay()->setTime(10, 0)->toDateTimeString(),
            'meeting_place' => 'Devant la pharmacie Sandaga',
        ])->assertOk()
            ->assertJsonPath('status', 'planned')
            ->assertJsonPath('method', 'agent_pickup')
            ->assertJsonPath('pickup_agent.uuid', $agentB->uuid);

        $this->asUser($agentB)->postJson("$url/collected")->assertOk()->assertJsonPath('status', 'collected');

        $doc = Document::where('uuid', $docUuid)->first();
        $this->assertSame($this->commissariatB->id, $doc->custody_organization_id);
        $this->assertSame(Document::LIFECYCLE_IN_CUSTODY, $doc->lifecycle_status);
        $this->assertSame($agentB->id, $c->fresh()->collected_by);

        // Historique complet visible sur la fiche.
        $history = $this->asUser($agentB)->getJson($url)->json('history');
        $this->assertSame(['unassigned', 'assigned', 'assigned', 'contacted', 'planned', 'collected'], array_column($history, 'to'));

        // Dossier clos : plus d'action possible.
        $this->asUser($agentB)->postJson("$url/contact", ['outcome' => 'reached'])->assertForbidden();

        // Le document est à la structure : une demande de restitution devient un retrait au guichet.
        $this->asUser($this->citizen())->postJson("/api/documents/{$docUuid}/restitution")
            ->assertCreated()->assertJsonPath('channel', 'counter')->assertJsonPath('custody.name', $this->commissariatB->name);
    }

    public function test_finder_can_bring_it_himself(): void
    {
        $this->publish(null, self::NEAR_PLATEAU);
        $c = $this->collection();

        $this->asUser($this->agent($this->commissariatB))->postJson('/api/v2/collections/' . $c->uuid . '/plan', ['method' => 'drop_off'])
            ->assertOk()->assertJsonPath('method', 'drop_off')->assertJsonPath('pickup_agent', null);
    }

    public function test_pickup_agent_must_belong_to_the_structure(): void
    {
        $this->publish(null, self::NEAR_PLATEAU);
        $c = $this->collection();

        $this->asUser($this->agent($this->commissariatB))->postJson('/api/v2/collections/' . $c->uuid . '/plan', [
            'method' => 'agent_pickup', 'pickup_agent_uuid' => $this->agent($this->commissariatA)->uuid,
        ])->assertUnprocessable()->assertJsonValidationErrors('pickup_agent_uuid');
    }

    public function test_decline_moves_the_case_to_the_next_nearest_structure(): void
    {
        $this->publish(null, self::NEAR_PLATEAU);
        $c      = $this->collection();
        $agentB = $this->agent($this->commissariatB);

        $this->asUser($agentB)->postJson('/api/v2/collections/' . $c->uuid . '/decline', ['reason' => 'Pas de véhicule cette semaine'])
            ->assertOk()
            ->assertJsonPath('organization.uuid', $this->commissariatA->uuid)
            ->assertJsonPath('finder.phone', '••••••433'); // masqué : le dossier n'est plus à B

        $this->assertSame([$this->commissariatB->id], $c->fresh()->declined_organization_ids);
        $this->asUser($agentB)->getJson('/api/v2/collections/' . $c->uuid)->assertForbidden();

        // A refuse aussi, puis Z : plus personne → super_admin.
        $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/collections/' . $c->uuid . '/decline', ['reason' => 'Effectif réduit'])
            ->assertOk()->assertJsonPath('organization.uuid', $this->brigadeZ->uuid);
        $this->asUser($this->agent($this->brigadeZ))->postJson('/api/v2/collections/' . $c->uuid . '/decline', ['reason' => 'Trop loin de nous'])->assertOk();

        $this->assertSame(CollectionRequest::STATUS_UNASSIGNED, $c->fresh()->status);
        $this->asUser($this->admin())->getJson('/api/v2/collections?status=unassigned')->assertJsonCount(1, 'data');
    }

    public function test_transfer_to_another_structure(): void
    {
        $this->publish(null, self::NEAR_PLATEAU);
        $c      = $this->collection();
        $agentB = $this->agent($this->commissariatB);
        $url    = '/api/v2/collections/' . $c->uuid . '/transfer';

        $this->asUser($agentB)->postJson($url, ['organization_uuid' => $this->police->uuid, 'reason' => 'Mauvaise cible'])
            ->assertUnprocessable()->assertJsonValidationErrors('organization_uuid');

        $this->asUser($agentB)->postJson($url, ['organization_uuid' => $this->brigadeZ->uuid, 'reason' => 'Le trouveur est rentré à Ziguinchor'])
            ->assertOk()
            ->assertJsonPath('organization.uuid', $this->brigadeZ->uuid)
            ->assertJsonPath('assignment_reason', 'transfer');
    }

    public function test_structure_not_accepting_collections_is_skipped(): void
    {
        $this->commissariatB->update(['accepts_collections' => false]);

        $this->publish(null, self::NEAR_PLATEAU);

        $this->assertSame($this->commissariatA->id, $this->collection()->organization_id);
    }

    public function test_case_with_an_identified_owner_comes_first(): void
    {
        $this->publish(null, ['OwnerFirstName' => 'Moussa', 'OwnerLastName' => 'Diop'] + self::NEAR_PLATEAU);

        $this->asUser($this->citizen())->postJson('/api/declarations', [
            'Title' => 'Perte', 'FirstNameInDoc' => 'Awa', 'LastNameInDoc' => 'Ndiaye', 'document_type_id' => $this->cni->id,
        ])->assertCreated();
        $this->publish(null, self::NEAR_PLATEAU);

        $list = $this->asUser($this->agent($this->commissariatB))->getJson('/api/v2/collections')->json('data');
        $this->assertCount(2, $list);
        $this->assertTrue($list[0]['priority']);
        $this->assertSame('Ndiaye', $list[0]['document']['last_name']);
        $this->assertFalse($list[1]['priority']);
    }

    public function test_direct_handover_by_the_finder_cancels_the_collection(): void
    {
        $finder  = $this->citizen();
        $docUuid = $this->publish($finder, self::NEAR_PLATEAU)->json('document.uuid');

        $this->asUser($finder)->post('/api/documents/' . $docUuid, ['_method' => 'PUT', 'statut' => 'récupéré'], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(CollectionRequest::STATUS_CANCELLED, $this->collection()->status);
    }

    public function test_deleting_the_publication_cancels_the_collection(): void
    {
        $finder  = $this->citizen();
        $docUuid = $this->publish($finder, self::NEAR_PLATEAU)->json('document.uuid');

        $this->asUser($finder)->deleteJson('/api/documents/' . $docUuid)->assertOk();

        $this->assertSame(CollectionRequest::STATUS_CANCELLED, $this->collection()->status);
    }

    public function test_manager_sets_the_position_of_his_structure(): void
    {
        $this->asUser($this->manager($this->commissariatA))
            ->patchJson('/api/v2/organizations/' . $this->commissariatA->uuid, ['latitude' => 14.72, 'longitude' => -17.46, 'accepts_collections' => false])
            ->assertOk()
            ->assertJsonPath('latitude', 14.72)
            ->assertJsonPath('accepts_collections', false);
    }

    public function test_distance_calculation(): void
    {
        // Dakar Plateau → Ziguinchor : environ 270 km à vol d'oiseau.
        $d = NearestStructureFinder::distanceKm(14.6700, -17.4380, 12.5640, -16.2720);
        $this->assertEqualsWithDelta(265, $d, 15);
    }
}
