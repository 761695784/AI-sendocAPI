<?php

namespace Tests\Feature\Tags;

use App\Jobs\SendSmsJob;
use App\Models\CollectionRequest;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\DocumentTag;
use App\Models\DocumentType;
use App\Models\TagScan;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * QR code v2 : un QR par document ; le scan prévient le propriétaire et ouvre une collecte.
 */
class DocumentTagTest extends OrganizationTestCase
{
    private DocumentType $cni;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
        Bus::fake([SendSmsJob::class]);
        $this->cni   = DocumentType::factory()->create(['TypeName' => 'Carte nationale d\'identité']);
        $this->owner = $this->citizen(['FirstName' => 'Awa', 'LastName' => 'Ndiaye', 'Phone' => '+221771112233']);

        $dakar = Territory::where('code', 'SN-DK-DAKAR')->value('id');
        $this->commissariatB->update(['territory_id' => $dakar, 'latitude' => 14.6700, 'longitude' => -17.4380, 'phone' => '338003344']);
    }

    private function createTag(): DocumentTag
    {
        $uuid = $this->asUser($this->owner)->postJson('/api/document-tags', [
            'document_type_id' => $this->cni->id, 'label' => 'Ma CNI', 'doc_number' => '1 234 5678 90123',
        ])->assertCreated()->json('uuid');

        return DocumentTag::where('uuid', $uuid)->firstOrFail();
    }

    private function scan(DocumentTag $tag, array $data = [])
    {
        return $this->postJson("/api/v2/tags/{$tag->token}/found", array_merge([
            'finder_phone' => '78 555 44 33', 'finder_name' => 'Moussa', 'place' => 'Marché Sandaga',
            'latitude' => 14.6720, 'longitude' => -17.4400, 'contact_consent' => true,
        ], $data));
    }

    public function test_citizen_creates_one_qr_per_document(): void
    {
        $response = $this->asUser($this->owner)->postJson('/api/document-tags', ['document_type_id' => $this->cni->id, 'label' => 'Ma CNI'])
            ->assertCreated()->assertJsonPath('status', 'active')->assertJsonPath('document_type', 'Carte nationale d\'identité');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('qr_svg'));
        $this->assertStringContainsString('/t/', $response->json('scan_url'));

        $this->asUser($this->owner)->postJson('/api/document-tags', ['document_type_id' => $this->cni->id])->assertUnprocessable();
        $this->asUser($this->citizen())->getJson('/api/document-tags/' . $response->json('uuid'))->assertNotFound();
        $this->asUser($this->owner)->getJson('/api/document-tags')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_scan_page_reveals_no_personal_data(): void
    {
        $tag  = $this->createTag();
        $view = $this->getJson("/api/v2/tags/{$tag->token}")->assertOk()->assertJsonPath('valid', true);

        $this->assertStringNotContainsString('Ndiaye', $view->getContent());
        $this->assertStringNotContainsString('0123', $view->getContent());
        $this->getJson('/api/v2/tags/' . str_repeat('x', 32))->assertNotFound()->assertJsonPath('valid', false);
    }

    public function test_finder_scan_alerts_the_owner_and_opens_a_collection(): void
    {
        $tag = $this->createTag();

        $this->scan($tag)->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('structure.name', 'Commissariat B');

        // Collecte ouverte chez la structure la plus proche.
        $collection = CollectionRequest::sole();
        $this->assertSame($this->commissariatB->id, $collection->organization_id);
        $this->assertSame('Moussa', $collection->finder_name);

        // Document créé, non publié dans la liste publique.
        $doc = Document::sole();
        $this->assertSame(Document::CHANNEL_QR_SCAN, $doc->found_channel);
        $this->assertNull($doc->user_id);
        $this->assertSame('Ndiaye', $doc->OwnerLastName);
        $this->getJson('/api/documents')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/documents/' . $doc->uuid)->assertNotFound();

        // Propriétaire prévenu : structure + contact du trouveur.
        Bus::assertDispatched(SendSmsJob::class, fn ($j) => $j->phone === '+221771112233'
            && str_contains($j->message, 'Commissariat B') && str_contains($j->message, '78 555 44 33') && str_contains($j->message, '(Ma CNI)'));

        $this->assertSame(1, $tag->fresh()->scans_count);

        // Même trouveur dans les 24 h : pas de nouveau signalement.
        $this->scan($tag)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, TagScan::count());
        $this->assertSame(1, Document::count());

        // La structure récupère le document → le propriétaire sait où aller.
        $this->asUser($this->agent($this->commissariatB))->postJson("/api/v2/collections/{$collection->uuid}/collected")->assertOk();
        Bus::assertDispatched(SendSmsJob::class, fn ($j) => $j->phone === '+221771112233' && str_contains($j->message, 'conservé par Commissariat B (tél. 338003344)'));
    }

    public function test_finder_can_refuse_to_share_his_number_and_the_collection(): void
    {
        $tag = $this->createTag();

        $this->scan($tag, ['contact_consent' => false, 'allow_owner_contact' => false])->assertOk()->assertJsonPath('structure', null);

        $this->assertSame(0, CollectionRequest::count());
        Bus::assertDispatched(SendSmsJob::class, fn ($j) => $j->phone === '+221771112233' && ! str_contains($j->message, '785554433') && ! str_contains($j->message, '78 555'));
    }

    public function test_invalid_phone_and_revoked_tag(): void
    {
        $tag = $this->createTag();
        $this->scan($tag, ['finder_phone' => '12'])->assertUnprocessable()->assertJsonValidationErrors('finder_phone');

        $this->asUser($this->owner)->postJson("/api/document-tags/{$tag->uuid}/revoke")->assertOk()->assertJsonPath('status', 'revoked');
        $this->getJson("/api/v2/tags/{$tag->token}")->assertNotFound();
        $this->scan($tag)->assertNotFound();
    }

    public function test_renewal_in_pilot_is_immediate(): void
    {
        $old = $this->createTag();

        $response = $this->asUser($this->owner)->postJson("/api/document-tags/{$old->uuid}/renew")->assertCreated()
            ->assertJsonPath('payment_required', false)
            ->assertJsonPath('tag.status', 'active')
            ->assertJsonPath('charge.status', 'waived_pilot')
            ->assertJsonPath('charge.list_amount', 1000);

        $this->assertSame(DocumentTag::STATUS_REPLACED, $old->fresh()->status);
        $this->getJson("/api/v2/tags/{$old->token}")->assertNotFound();
        $new = DocumentTag::where('uuid', $response->json('tag.uuid'))->first();
        $this->getJson("/api/v2/tags/{$new->token}")->assertOk();
    }

    public function test_renewal_in_live_mode_waits_for_wave(): void
    {
        config(['billing.mode' => 'live', 'services.wave.api_key' => 'k', 'services.wave.webhook_secret' => 's']);
        Http::fake(['api.wave.com/*' => Http::response(['id' => 'cos-tag', 'wave_launch_url' => 'https://pay.wave.com/c/cos-tag'], 200)]);
        $old = $this->createTag();

        $resp = $this->asUser($this->owner)->postJson("/api/document-tags/{$old->uuid}/renew")->assertStatus(402)
            ->assertJsonPath('payment_required', true)
            ->assertJsonPath('tag.status', 'pending_payment')
            ->assertJsonPath('tag.qr_svg', null)
            ->assertJsonPath('charge.amount', 1000);
        $this->assertTrue($old->fresh()->isActive()); // l'ancien fonctionne jusqu'au paiement

        $this->asUser($this->owner)->postJson('/api/charges/' . $resp->json('charge.uuid') . '/checkout')->assertOk();

        $body = json_encode(['type' => 'checkout.session.completed', 'data' => ['id' => 'cos-tag', 'payment_status' => 'succeeded', 'amount' => '1000']]);
        $t = time();
        $this->call('POST', '/api/webhooks/wave', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_WAVE_SIGNATURE' => "t={$t},v1=" . hash_hmac('sha256', $t . $body, 's'),
        ], $body)->assertOk();

        $this->assertSame(DocumentTag::STATUS_ACTIVE, DocumentTag::where('uuid', $resp->json('tag.uuid'))->value('status'));
        $this->assertSame(DocumentTag::STATUS_REPLACED, $old->fresh()->status);
    }

    public function test_scanned_document_matches_the_owner_declaration(): void
    {
        $this->asUser($this->owner)->postJson('/api/declarations', [
            'Title' => 'Perte CNI', 'FirstNameInDoc' => 'Awa', 'LastNameInDoc' => 'Ndiaye', 'document_type_id' => $this->cni->id,
        ])->assertCreated();

        $this->scan($this->createTag())->assertOk();

        $this->assertSame(1, DocumentMatch::count());
        $this->assertSame(Document::sole()->id, DocumentMatch::sole()->document_id);
    }
}
