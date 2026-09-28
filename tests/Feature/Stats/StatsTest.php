<?php

namespace Tests\Feature\Stats;

use App\Jobs\SendSmsJob;
use App\Models\CollectionRequest;
use App\Models\DocumentType;
use App\Models\InboxMessage;
use App\Models\Territory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Phase 6 : statistiques par périmètre et délais de réaction des structures.
 */
class StatsTest extends OrganizationTestCase
{
    private DocumentType $cni;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
        Bus::fake([SendSmsJob::class]);
        $this->cni = DocumentType::factory()->create(['TypeName' => 'Carte nationale d\'identité']);

        $dakar = Territory::where('code', 'SN-DK-DAKAR')->value('id');
        $this->commissariatA->update(['territory_id' => $dakar, 'latitude' => 14.7160, 'longitude' => -17.4580]);
        $this->commissariatB->update(['territory_id' => $dakar, 'latitude' => 14.6700, 'longitude' => -17.4380]);
    }

    /** Publication citoyenne près du commissariat B → collecte affectée à B. */
    private function publishNearB(string $last = 'Sarr'): CollectionRequest
    {
        $this->asUser($this->citizen())->post('/api/documents', [
            'image' => UploadedFile::fake()->image('cni.jpg', 600, 400),
            'OwnerFirstName' => 'Moussa', 'OwnerLastName' => $last, 'Location' => 'Sandaga',
            'document_type_id' => $this->cni->id, 'contact_consent' => true, 'contact_phone' => '78 555 44 33',
            'latitude' => 14.6720, 'longitude' => -17.4400,
        ], ['Accept' => 'application/json'])->assertCreated();

        return CollectionRequest::latest('id')->first();
    }

    public function test_each_role_gets_its_own_perimeter(): void
    {
        $this->asUser($this->agent($this->commissariatA))->getJson('/api/v2/stats/overview')->assertForbidden();
        $this->asUser($this->citizen())->getJson('/api/v2/stats/overview')->assertForbidden();

        $this->asUser($this->manager($this->commissariatA))->getJson('/api/v2/stats/overview')->assertOk()
            ->assertJsonPath('scope.organization.uuid', $this->commissariatA->uuid)
            ->assertJsonPath('scope.structures', 1);

        $policeAdmin = $this->institutionAdmin($this->police);
        $this->asUser($policeAdmin)->getJson('/api/v2/stats/overview')->assertOk()
            ->assertJsonPath('scope.organization.uuid', $this->police->uuid)
            ->assertJsonPath('scope.structures', 4);
        $this->asUser($policeAdmin)->getJson('/api/v2/stats/overview?organization=' . $this->direction->uuid)->assertOk()
            ->assertJsonPath('scope.level', 'intermediate');
        $this->asUser($policeAdmin)->getJson('/api/v2/stats/overview?organization=' . $this->brigadeZ->uuid)->assertForbidden();

        // Un chef de structure ne voit pas le niveau supérieur.
        $this->asUser($this->manager($this->commissariatA))->getJson('/api/v2/stats/overview?organization=' . $this->direction->uuid)->assertForbidden();

        $this->asUser($this->admin())->getJson('/api/v2/stats/overview')->assertOk()
            ->assertJsonPath('scope.level', 'national')
            ->assertJsonPath('declarations.online', 0);
    }

    public function test_reaction_delay_of_structures_on_collections(): void
    {
        $called = $this->publishNearB('Sarr');
        $this->publishNearB('Diop'); // jamais appelé

        $agentB = $this->agent($this->commissariatB);
        $this->travel(2)->hours();
        $this->asUser($agentB)->postJson("/api/v2/collections/{$called->uuid}/contact", ['outcome' => 'reached'])->assertOk();
        $this->travel(28)->hours();

        $overview = $this->asUser($this->manager($this->commissariatB))->getJson('/api/v2/stats/overview')->assertOk();
        $overview->assertJsonPath('collections.assigned', 2)
            ->assertJsonPath('collections.median_first_contact_hours', 2)
            ->assertJsonPath('collections.contacted_within_sla_pct', 50)
            ->assertJsonPath('collections.overdue_now', 1);

        // Comparatif : B (en retard) apparaît avant A.
        $rows = $this->asUser($this->institutionAdmin($this->police))->getJson('/api/v2/stats/structures')->assertOk()->json('data');
        $this->assertSame('Commissariat B', $rows[0]['name']);
        $this->assertSame(1, $rows[0]['collections_overdue_now']);
        $this->assertSame(0, collect($rows)->firstWhere('name', 'Commissariat A')['collections_assigned']);

        // Liste des collectes en retard.
        $this->asUser($this->institutionAdmin($this->police))->getJson('/api/v2/stats/overdue-collections')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.structure.name', 'Commissariat B')
            ->assertJsonPath('data.0.hours_waiting', 30);

        // Export CSV (Excel).
        $csv = $this->asUser($this->institutionAdmin($this->police))->get('/api/v2/stats/structures?format=csv')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('Commissariat B', $csv->streamedContent());
        $this->assertStringContainsString('1er appel (h, médiane)', $csv->streamedContent());
    }

    public function test_sla_monitor_reminds_then_escalates_once(): void
    {
        $c = $this->publishNearB();
        InboxMessage::query()->delete(); // on ne garde que les relances

        $this->travel(25)->hours();
        $this->artisan('doctrack:sla-check')->assertSuccessful();
        $this->assertSame(1, InboxMessage::where('event', 'collection.sla_reminder')->where('organization_id', $this->commissariatB->id)->count());
        $this->assertSame(0, InboxMessage::where('event', 'collection.sla_escalation')->count());

        $this->travel(24)->hours();
        $this->artisan('doctrack:sla-check')->assertSuccessful();
        $this->artisan('doctrack:sla-check')->assertSuccessful();

        $escalation = InboxMessage::where('event', 'collection.sla_escalation')->sole();
        $this->assertSame($this->direction->id, $escalation->organization_id); // structure supérieure
        $this->assertStringContainsString('Commissariat B', $escalation->title);
        $this->assertSame(1, InboxMessage::where('event', 'collection.sla_reminder')->count());

        // Un transfert repart de zéro pour la nouvelle structure.
        $this->asUser($this->agent($this->commissariatB))->postJson("/api/v2/collections/{$c->uuid}/transfer", [
            'organization_uuid' => $this->commissariatA->uuid, 'reason' => 'Plus proche du trouveur',
        ])->assertOk();
        $this->assertNull($c->fresh()->reminded_at);
        $this->artisan('doctrack:sla-check')->assertSuccessful();
        $this->assertSame(0, InboxMessage::where('event', 'collection.sla_reminder')->where('organization_id', $this->commissariatA->id)->count());
    }

    public function test_slow_match_reviews_are_reminded(): void
    {
        $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/declarations', [
            'citizen' => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 123 45 67'],
            'items'   => [['document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye']],
        ])->assertCreated();
        $this->asUser($this->agent($this->commissariatB))->postJson('/api/v2/found-documents', [
            'document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Njaay',
        ])->assertCreated();

        $this->travel(49)->hours();
        $this->artisan('doctrack:sla-check')->assertSuccessful();
        $this->artisan('doctrack:sla-check')->assertSuccessful();

        $this->assertSame(1, InboxMessage::where('event', 'match.review_reminder')->where('organization_id', $this->commissariatB->id)->count());
        $this->asUser($this->manager($this->commissariatB))->getJson('/api/v2/stats/overview')
            ->assertJsonPath('matches.pending_review_now', 1)
            ->assertJsonPath('matches.overdue_reviews_now', 1);
    }

    public function test_full_chain_is_counted_nationally(): void
    {
        $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/declarations', [
            'citizen' => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 123 45 67'],
            'items'   => [['document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye', 'doc_number' => '1234567890123']],
        ])->assertCreated();
        $uuid = $this->asUser($this->agent($this->commissariatB))->postJson('/api/v2/found-documents', [
            'document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye', 'doc_number' => '1234567890123',
        ])->json('uuid');

        $this->travel(3)->days();
        $this->asUser($this->agent($this->commissariatB))->postJson("/api/v2/found-documents/{$uuid}/restitution", [
            'recipient_type' => 'owner',
            'owner'  => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'id_type' => 'passeport', 'id_number' => 'A0123456'],
            'checks' => ['identity_photo_matches' => true],
        ])->assertCreated();

        $this->asUser($this->admin())->getJson('/api/v2/stats/overview')->assertOk()
            ->assertJsonPath('declarations.counter', 1)
            ->assertJsonPath('found_documents.deposited_at_counter', 1)
            ->assertJsonPath('found_documents.entered_custody', 1)
            ->assertJsonPath('found_documents.restitution_rate_pct', 100)
            ->assertJsonPath('found_documents.in_custody_now', 0)
            ->assertJsonPath('matches.auto_confirmed', 1)
            ->assertJsonPath('restitutions.completed', 1)
            ->assertJsonPath('restitutions.median_days_in_custody', 3);

        // Chaque structure ne compte que ce qui la concerne.
        $this->asUser($this->manager($this->commissariatA))->getJson('/api/v2/stats/overview')
            ->assertJsonPath('declarations.counter', 1)
            ->assertJsonPath('restitutions.completed', 0);

        // Courbe hebdomadaire des restitutions.
        $points = $this->asUser($this->admin())->getJson('/api/v2/stats/timeseries?metric=restitutions&interval=day&from=' . now()->subDays(6)->toDateString())
            ->assertOk()->json('points');
        $this->assertCount(7, $points);
        $this->assertSame(1, collect($points)->sum('value'));
        $this->assertSame(1, end($points)['value']);

        $this->asUser($this->admin())->getJson('/api/v2/stats/timeseries?metric=inconnu')->assertUnprocessable();
    }
}
