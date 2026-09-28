<?php

namespace Tests\Feature\Notifications;

use App\Jobs\SendSmsJob;
use App\Models\CollectionRequest;
use App\Models\DeclarationDePerte;
use App\Models\DocumentType;
use App\Models\InboxMessage;
use App\Models\NotificationDelivery;
use App\Models\Territory;
use App\Services\Notifications\Notifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Phase 4 : chaque événement prévient les bonnes personnes, et chaque envoi est tracé.
 */
class NotificationTest extends OrganizationTestCase
{
    private DocumentType $cni;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
        $this->cni = DocumentType::factory()->create(['TypeName' => 'Carte nationale d\'identité']);

        $dakar = Territory::where('code', 'SN-DK-DAKAR')->value('id');
        $this->commissariatA->update(['territory_id' => $dakar, 'latitude' => 14.7160, 'longitude' => -17.4580, 'phone' => '338001122', 'email' => 'dieuppeul@police.sn']);
        $this->commissariatB->update(['territory_id' => $dakar, 'latitude' => 14.6700, 'longitude' => -17.4380, 'phone' => '338003344']);
    }

    private function declareAtCounter(array $overrides = [])
    {
        return $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/declarations', array_merge([
            'citizen' => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 123 45 67'],
            'items'   => [['document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye']],
        ], $overrides))->assertCreated();
    }

    private function publishWithCollection(array $data = [])
    {
        return $this->asUser($this->citizen())->post('/api/documents', array_merge([
            'image'            => UploadedFile::fake()->image('cni.jpg', 600, 400),
            'OwnerFirstName'   => 'Moussa',
            'OwnerLastName'    => 'Sarr',
            'Location'         => 'Marché Sandaga',
            'document_type_id' => $this->cni->id,
            'contact_consent'  => true,
            'contact_phone'    => '78 555 44 33',
            'latitude'         => 14.6720,
            'longitude'        => -17.4400,
        ], $data), ['Accept' => 'application/json'])->assertCreated();
    }

    private function sms(string $event): ?NotificationDelivery
    {
        return NotificationDelivery::where('event', $event)->where('channel', 'sms')->latest('id')->first();
    }

    // ── Guichet ───────────────────────────────────────────────

    public function test_victim_gets_an_sms_receipt_with_the_verification_link(): void
    {
        Bus::fake([SendSmsJob::class]);
        $reference = $this->declareAtCounter()->json('reference');

        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '771234567'
            || str_contains((string) $job->phone, '771234567'));
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => str_contains($job->message, $reference)
            && str_contains($job->message, '/verifier/') && str_contains($job->message, 'Commissariat A'));

        $delivery = $this->sms('counter_declaration.registered');
        $this->assertSame('pending', $delivery->status); // job simulé
        $this->assertStringEndsWith('4567', $delivery->destination_masked);
        $this->assertStringNotContainsString('771234567', $delivery->destination_masked);
        $this->assertSame($this->commissariatA->id, $delivery->organization_id);

        $uuid = DeclarationDePerte::sole()->uuid;
        $this->asUser($this->manager($this->commissariatA))->postJson("/api/v2/declarations/{$uuid}/cancel", ['reason' => 'Doublon de saisie'])->assertOk();
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => str_contains($job->message, 'annulée') && str_contains($job->message, 'Doublon de saisie'));
    }

    public function test_finder_depositing_at_the_counter_is_thanked(): void
    {
        Bus::fake([SendSmsJob::class]);
        $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/found-documents', [
            'document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye',
            'finder' => ['first_name' => 'Ibrahima', 'last_name' => 'Fall', 'phone' => '76 111 22 33'],
        ])->assertCreated();

        Bus::assertDispatched(SendSmsJob::class, fn ($job) => str_contains((string) $job->phone, '761112233') && str_contains($job->message, 'merci'));
    }

    // ── Collecte ──────────────────────────────────────────────

    public function test_assigned_structure_gets_inbox_sms_and_email_and_the_finder_knows_who_will_call(): void
    {
        Bus::fake([SendSmsJob::class]);
        $this->publishWithCollection();

        // Commissariat B (le plus proche) : boîte de réception + SMS au numéro de la structure.
        $message = InboxMessage::sole();
        $this->assertSame($this->commissariatB->id, $message->organization_id);
        $this->assertStringContainsString('Document à récupérer', $message->title);
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '338003344' && str_contains($job->message, 'nouveau document à récupérer'));

        // Le trouveur sait qui va l'appeler.
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => str_contains((string) $job->phone, '785554433')
            && str_contains($job->message, 'Commissariat B (tél. 338003344) va vous appeler'));

        // Pas d'email : la structure B n'a pas d'adresse.
        $this->assertSame(0, NotificationDelivery::where('channel', 'email')->where('event', 'collection.assigned')->count());

        // Transfert vers A : A reçoit à son tour (et par email, car A en a une).
        $c = CollectionRequest::sole();
        $this->asUser($this->agent($this->commissariatB))->postJson("/api/v2/collections/{$c->uuid}/transfer", [
            'organization_uuid' => $this->commissariatA->uuid, 'reason' => 'Plus proche du trouveur',
        ])->assertOk();
        $this->assertSame(1, InboxMessage::where('organization_id', $this->commissariatA->id)->count());
        $this->assertSame('d•••@police.sn', NotificationDelivery::where('channel', 'email')->where('event', 'collection.assigned')->sole()->destination_masked);
    }

    public function test_finder_receives_the_appointment(): void
    {
        Bus::fake([SendSmsJob::class]);
        $this->publishWithCollection();
        $c = CollectionRequest::sole();

        $this->asUser($this->agent($this->commissariatB))->postJson("/api/v2/collections/{$c->uuid}/plan", [
            'method' => 'agent_pickup', 'scheduled_at' => '2030-05-12 10:30:00', 'meeting_place' => 'Pharmacie Sandaga',
        ])->assertOk();

        Bus::assertDispatched(SendSmsJob::class, fn ($job) => str_contains($job->message, 'le 12/05 à 10h30')
            && str_contains($job->message, 'Pharmacie Sandaga'));
    }

    // ── Boîte de réception ────────────────────────────────────

    public function test_inbox_is_per_structure_with_unread_counter(): void
    {
        Bus::fake([SendSmsJob::class]);
        $this->publishWithCollection();
        $agentB = $this->agent($this->commissariatB);

        $this->asUser($agentB)->getJson('/api/v2/inbox/unread-count')->assertOk()->assertJsonPath('unread', 1);
        $uuid = $this->asUser($agentB)->getJson('/api/v2/inbox')->assertOk()
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('data.0.link', 'collections/' . CollectionRequest::sole()->uuid)
            ->json('data.0.uuid');

        $this->asUser($agentB)->postJson("/api/v2/inbox/{$uuid}/read")->assertOk();
        $this->asUser($agentB)->getJson('/api/v2/inbox/unread-count')->assertJsonPath('unread', 0);

        // Lu par un agent ≠ lu par son collègue.
        $this->asUser($this->agent($this->commissariatB))->getJson('/api/v2/inbox/unread-count')->assertJsonPath('unread', 1);

        // Autre structure : rien, et pas d'accès au message.
        $agentA = $this->agent($this->commissariatA);
        $this->asUser($agentA)->getJson('/api/v2/inbox')->assertJsonCount(0, 'data');
        $this->asUser($agentA)->postJson("/api/v2/inbox/{$uuid}/read")->assertNotFound();
    }

    public function test_match_to_review_lands_in_the_custody_structure_inbox_once(): void
    {
        Bus::fake([SendSmsJob::class]);
        $this->declareAtCounter();
        $this->asUser($this->agent($this->commissariatB))->postJson('/api/v2/found-documents', [
            'document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Njaay',
        ])->assertCreated();

        $message = InboxMessage::where('event', 'match.pending_review')->sole();
        $this->assertSame($this->commissariatB->id, $message->organization_id);
        $this->assertStringContainsString('Correspondance à vérifier', $message->title);

        // Recalcul : pas de second message.
        \App\Jobs\MatchDocumentJob::dispatchSync(\App\Models\Document::latest('id')->value('id'));
        $this->assertSame(1, InboxMessage::where('event', 'match.pending_review')->count());
    }

    // ── Journal des envois ────────────────────────────────────

    public function test_sms_delivery_is_marked_sent_or_failed_and_can_be_retried(): void
    {
        config(['services.orange.client_id' => 'id', 'services.orange.client_secret' => 'secret', 'services.orange.sender_address' => '+221770000000']);
        Http::fake([
            'api.orange.com/oauth/*'        => Http::response(['access_token' => 'tok'], 200),
            'api.orange.com/smsmessaging/*' => Http::sequence()->push([], 201)->push([], 500)->push([], 201),
        ]);

        $this->declareAtCounter(); // 1er SMS : accepté
        $this->assertSame('sent', $this->sms('counter_declaration.registered')->status);

        $this->declareAtCounter(['citizen' => ['first_name' => 'Awa', 'last_name' => 'Diop', 'phone' => '77 000 11 22']]); // 2e : refusé
        $failed = $this->sms('counter_declaration.registered');
        $this->assertSame('failed', $failed->status);

        $admin = $this->admin();
        $this->asUser($admin)->getJson('/api/v2/notification-deliveries?status=failed')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.preview', fn ($p) => ! str_contains($p, '770001122'));

        $this->asUser($admin)->postJson("/api/v2/notification-deliveries/{$failed->uuid}/retry")->assertOk()->assertJsonPath('status', 'sent');
        $this->asUser($admin)->postJson("/api/v2/notification-deliveries/{$failed->uuid}/retry")->assertStatus(409);

        $this->asUser($admin)->getJson('/api/v2/notification-deliveries/summary')->assertOk()
            ->assertJsonPath('sms_sent', 2)->assertJsonPath('sms_failed', 0);
    }

    public function test_missing_phone_is_traced_as_skipped(): void
    {
        app(Notifier::class)->sms('collection.collected.finder', null, ['structure' => 'X'], ['organization_id' => $this->commissariatA->id]);

        $d = NotificationDelivery::sole();
        $this->assertSame('skipped', $d->status);
        $this->assertSame('Aucun numéro', $d->error);
    }

    public function test_delivery_log_is_scoped(): void
    {
        Bus::fake([SendSmsJob::class]);
        $this->declareAtCounter(); // envoi rattaché au commissariat A

        $this->asUser($this->citizen())->getJson('/api/v2/notification-deliveries')->assertForbidden();
        $this->asUser($this->agent($this->commissariatA))->getJson('/api/v2/notification-deliveries')->assertForbidden(); // pas audit.view
        $this->asUser($this->manager($this->commissariatA))->getJson('/api/v2/notification-deliveries')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->manager($this->commissariatB))->getJson('/api/v2/notification-deliveries')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->institutionAdmin($this->police))->getJson('/api/v2/notification-deliveries')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_texts_come_from_the_configuration(): void
    {
        Bus::fake([SendSmsJob::class]);
        config(['notifications.sms' => array_merge(config('notifications.sms'), [
            'counter_declaration.registered' => 'Nouveau texte :reference',
        ])]);

        $reference = $this->declareAtCounter()->json('reference');

        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->message === 'Nouveau texte ' . $reference);
    }
}
