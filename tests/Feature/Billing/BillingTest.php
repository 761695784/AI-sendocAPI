<?php

namespace Tests\Feature\Billing;

use App\Jobs\SendSmsJob;
use App\Models\Charge;
use App\Models\DeclarationDePerte;
use App\Models\DocumentType;
use App\Models\PriceRule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Phase 7 : facturation (pilote gratuit tracé / live), espèces, exonération, Wave, tarifs, reversement.
 */
class BillingTest extends OrganizationTestCase
{
    private DocumentType $cni;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        Bus::fake([SendSmsJob::class]);
        $this->cni = DocumentType::factory()->create(['TypeName' => 'Carte nationale d\'identité']);
        config(['services.wave.api_key' => 'wave_test_key', 'services.wave.webhook_secret' => 'whsec_test']);
    }

    private function declare($agent = null)
    {
        return $this->asUser($agent ?? $this->agent($this->commissariatA))->postJson('/api/v2/declarations', [
            'citizen' => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 123 45 67'],
            'items'   => [['document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye']],
        ])->assertCreated();
    }

    private function waveWebhook(array $data, ?string $secret = 'whsec_test')
    {
        $body = json_encode(['id' => 'EV_1', 'type' => 'checkout.session.completed', 'data' => $data]);
        $t    = time();
        $sig  = hash_hmac('sha256', $t . $body, (string) $secret);

        return $this->call('POST', '/api/webhooks/wave', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_WAVE_SIGNATURE' => "t={$t},v1={$sig}",
        ], $body);
    }

    public function test_pilot_mode_is_free_but_records_the_theoretical_value(): void
    {
        $this->declare()
            ->assertJsonPath('charge.status', 'waived_pilot')
            ->assertJsonPath('charge.amount', 0)
            ->assertJsonPath('charge.list_amount', 3000)
            ->assertJsonPath('certificate.kind', 'official');

        $this->asUser($this->admin())->getJson('/api/v2/billing/report')->assertOk()
            ->assertJsonPath('mode', 'pilot')
            ->assertJsonPath('totals.pilot_count', 1)
            ->assertJsonPath('totals.pilot_theoretical_value', 3000)
            ->assertJsonPath('totals.collected', 0);
    }

    public function test_live_mode_certificate_waits_for_cash_payment(): void
    {
        config(['billing.mode' => 'live']);
        $agent    = $this->agent($this->commissariatA);
        $response = $this->declare($agent)
            ->assertJsonPath('charge.status', 'pending')
            ->assertJsonPath('charge.amount', 3000)
            ->assertJsonPath('certificate', null);
        Bus::assertNotDispatched(SendSmsJob::class);

        $uuid = $response->json('charge.uuid');
        $this->asUser($agent)->postJson("/api/v2/charges/{$uuid}/cash", ['amount_received' => 2000])->assertUnprocessable();
        $this->asUser($agent)->postJson("/api/v2/charges/{$uuid}/cash", ['amount_received' => 5000])->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('change_due', 2000)
            ->assertJsonPath('authority_amount', 1500)
            ->assertJsonPath('platform_amount', 1500)
            ->assertJsonPath('paid_with', 'cash');

        $this->assertNotNull(DeclarationDePerte::sole()->certificat);
        Bus::assertDispatched(SendSmsJob::class, fn ($j) => str_contains($j->message, DeclarationDePerte::sole()->reference));

        $this->asUser($agent)->postJson("/api/v2/charges/{$uuid}/cash")->assertUnprocessable();
        $this->asUser($this->agent($this->commissariatB))->postJson("/api/v2/charges/{$uuid}/cash")->assertForbidden();
    }

    public function test_manager_can_exempt_with_a_reason(): void
    {
        config(['billing.mode' => 'live']);
        $uuid = $this->declare()->json('charge.uuid');

        $this->asUser($this->agent($this->commissariatA))->postJson("/api/v2/charges/{$uuid}/exempt", ['reason' => 'Victime d\'agression'])->assertForbidden();
        $this->asUser($this->manager($this->commissariatA))->postJson("/api/v2/charges/{$uuid}/exempt", ['reason' => 'Victime d\'agression, sans ressources'])
            ->assertOk()->assertJsonPath('status', 'exempted')->assertJsonPath('amount', 0);

        $this->assertNotNull(DeclarationDePerte::sole()->certificat);
        $this->asUser($this->admin())->getJson('/api/v2/billing/report')
            ->assertJsonPath('totals.exempted_count', 1)
            ->assertJsonPath('totals.exempted_value', 3000);
    }

    public function test_wave_payment_at_the_counter(): void
    {
        config(['billing.mode' => 'live']);
        Http::fake(['api.wave.com/v1/checkout/sessions' => Http::response(['id' => 'cos-123', 'wave_launch_url' => 'https://pay.wave.com/c/cos-123'], 200)]);

        $agent = $this->agent($this->commissariatA);
        $uuid  = $this->declare($agent)->json('charge.uuid');

        $this->asUser($agent)->postJson("/api/v2/charges/{$uuid}/checkout", ['payer_phone' => '77 123 45 67'])->assertOk()
            ->assertJsonPath('checkout_url', 'https://pay.wave.com/c/cos-123');
        Http::assertSent(fn ($r) => $r['amount'] === '3000' && $r['currency'] === 'XOF'
            && $r['restrict_payer_mobile'] === '+221771234567' && $r->hasHeader('Authorization', 'Bearer wave_test_key'));

        // Signature fausse → refusée, rien ne bouge.
        $this->waveWebhook(['id' => 'cos-123', 'payment_status' => 'succeeded', 'amount' => '3000'], 'mauvais_secret')->assertStatus(401);
        $this->assertSame('pending', Charge::sole()->status);

        // Montant falsifié → refusé.
        $this->waveWebhook(['id' => 'cos-123', 'payment_status' => 'succeeded', 'amount' => '100'])->assertOk()->assertJsonPath('result', 'amount_mismatch');
        $this->assertSame('pending', Charge::sole()->status);
    }

    public function test_wave_webhook_confirms_and_is_idempotent(): void
    {
        config(['billing.mode' => 'live']);
        Http::fake(['api.wave.com/*' => Http::response(['id' => 'cos-9', 'wave_launch_url' => 'https://pay.wave.com/c/cos-9'], 200)]);
        $agent = $this->agent($this->commissariatA);
        $uuid  = $this->declare($agent)->json('charge.uuid');
        $this->asUser($agent)->postJson("/api/v2/charges/{$uuid}/checkout")->assertOk();

        $this->waveWebhook(['id' => 'cos-9', 'payment_status' => 'succeeded', 'checkout_status' => 'complete', 'amount' => '3000', 'transaction_id' => 'TCN1'])
            ->assertOk()->assertJsonPath('result', 'ok');
        $this->waveWebhook(['id' => 'cos-9', 'payment_status' => 'succeeded', 'amount' => '3000', 'transaction_id' => 'TCN1'])->assertOk();

        $charge = Charge::sole();
        $this->assertSame('paid', $charge->status);
        $this->assertSame(1, $charge->payments()->where('status', 'succeeded')->count());
        $this->assertSame(1, DeclarationDePerte::sole()->certificat()->count());

        $this->asUser($this->manager($this->commissariatA))->getJson('/api/v2/billing/report')->assertOk()
            ->assertJsonPath('totals.collected', 3000)
            ->assertJsonPath('totals.authority_share', 1500)
            ->assertJsonPath('totals.platform_share', 1500);
    }

    public function test_super_admin_sets_national_and_institution_prices(): void
    {
        $admin = $this->admin();
        $this->asUser($this->institutionAdmin($this->police))->postJson('/api/v2/billing/prices', ['service' => 'counter_declaration', 'amount' => 1, 'authority_share_pct' => 50])->assertForbidden();

        $this->asUser($admin)->postJson('/api/v2/billing/prices', ['service' => 'counter_declaration', 'amount' => 2000, 'authority_share_pct' => 60])->assertCreated();
        $this->asUser($admin)->postJson('/api/v2/billing/prices', [
            'service' => 'counter_declaration', 'amount' => 2500, 'authority_share_pct' => 50, 'institution_uuid' => $this->police->uuid,
        ])->assertCreated();
        $this->asUser($admin)->postJson('/api/v2/billing/prices', [
            'service' => 'counter_declaration', 'amount' => 2500, 'authority_share_pct' => 50, 'institution_uuid' => $this->commissariatA->uuid,
        ])->assertStatus(422);

        config(['billing.mode' => 'live']);
        $this->declare($this->agent($this->commissariatA))->assertJsonPath('charge.amount', 2500);   // Police
        $this->declare($this->agent($this->brigadeZ))->assertJsonPath('charge.amount', 2000);        // Gendarmerie : national

        $services = $this->asUser($admin)->getJson('/api/v2/billing/services')->assertOk()->json('data');
        $decl = collect($services)->firstWhere('code', 'counter_declaration');
        $this->assertSame(2000, $decl['current']['amount']);
        $this->assertNotNull(collect($decl['history'])->firstWhere('amount', 3000)['valid_to']); // ancien tarif clôturé
        $this->assertSame(1000, collect($services)->firstWhere('code', 'qr_renewal')['current']['amount']);
        $this->assertSame(0, collect($services)->firstWhere('code', 'restitution')['current']['amount']);
    }

    public function test_report_scope_and_csv(): void
    {
        $this->declare($this->agent($this->commissariatA));
        $this->declare($this->agent($this->brigadeZ));

        $this->asUser($this->manager($this->commissariatA))->getJson('/api/v2/billing/report')->assertOk()->assertJsonPath('totals.count', 1);
        $this->asUser($this->institutionAdmin($this->police))->getJson('/api/v2/billing/report?month=' . now()->format('Y-m'))->assertOk()
            ->assertJsonPath('totals.count', 1)
            ->assertJsonPath('by_institution.0.institution', 'Police nationale');
        $this->asUser($this->admin())->getJson('/api/v2/billing/report')->assertJsonPath('totals.count', 2);
        $this->asUser($this->agent($this->commissariatA))->getJson('/api/v2/billing/report')->assertForbidden();

        $csv = $this->asUser($this->admin())->get('/api/v2/billing/report?format=csv')->assertOk();
        $this->assertStringContainsString('Valeur théorique pilote', $csv->streamedContent());
        $this->assertStringContainsString('6000', $csv->streamedContent());
    }

    public function test_personal_qr_renewal_is_billed(): void
    {
        $citizen = $this->citizen();
        $old     = $citizen->qr_code_token;

        $this->asUser($citizen)->postJson('/api/renew-qr-code')->assertOk()->assertJsonPath('charge.status', 'waived_pilot');
        $this->assertNotSame($old, $citizen->fresh()->qr_code_token);

        config(['billing.mode' => 'live']);
        $current = $citizen->fresh()->qr_code_token;
        $uuid    = $this->asUser($citizen)->postJson('/api/renew-qr-code')->assertStatus(402)
            ->assertJsonPath('payment_required', true)
            ->assertJsonPath('charge.amount', 1000)
            ->json('charge.uuid');
        $this->assertSame($current, $citizen->fresh()->qr_code_token);

        Http::fake(['api.wave.com/*' => Http::response(['id' => 'cos-qr', 'wave_launch_url' => 'https://pay.wave.com/c/cos-qr'], 200)]);
        $this->asUser($citizen)->postJson("/api/charges/{$uuid}/checkout")->assertOk()->assertJsonPath('checkout_url', 'https://pay.wave.com/c/cos-qr');
        $this->asUser($this->citizen())->postJson("/api/charges/{$uuid}/checkout")->assertNotFound();
        $this->asUser($citizen)->getJson('/api/my-charges')->assertOk()->assertJsonCount(2, 'data');

        $this->waveWebhook(['id' => 'cos-qr', 'payment_status' => 'succeeded', 'amount' => '1000'])->assertOk();
        $this->assertNotSame($current, $citizen->fresh()->qr_code_token);
    }
}
