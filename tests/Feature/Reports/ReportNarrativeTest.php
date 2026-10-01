<?php

namespace Tests\Feature\Reports;

use App\Jobs\SendSmsJob;
use App\Models\ActivityReport;
use App\Models\DocumentType;
use App\Models\InboxMessage;
use App\Services\Reports\ReportNarrator;
use App\Support\PrivateFiles;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;
use ZipArchive;

/**
 * Rapports : synthèse rédigée par un modèle de langage (Qwen, puis GLM en
 * secours), chiffres vérifiés, validation par le super admin avant envoi.
 */
class ReportNarrativeTest extends OrganizationTestCase
{
    private DocumentType $cni;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
        Bus::fake([SendSmsJob::class]);
        $this->cni = DocumentType::factory()->create(['TypeName' => "Carte nationale d'identité"]);

        config([
            'ai.reports.enabled'            => true,
            'ai.reports.providers'          => ['qwen', 'glm'],
            'ai.reports.require_validation' => true,
            'ai.endpoints.qwen.key'         => 'qwen-key',
            'ai.endpoints.glm.key'          => 'glm-key',
        ]);

        // Un peu d'activité : 1 déclaration, 1 dépôt (même numéro → correspondance).
        $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/declarations', [
            'citizen' => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 123 45 67'],
            'items'   => [['document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye', 'doc_number' => '1234567890123']],
        ])->assertCreated();
        $this->asUser($this->agent($this->commissariatB))->postJson('/api/v2/found-documents', [
            'document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye', 'doc_number' => '1234567890123',
        ])->assertCreated();
    }

    private function answer(string $summary, array $extra = []): array
    {
        return ['model' => 'qwen-plus', 'choices' => [['message' => ['content' => json_encode(array_merge([
            'summary_fr'      => $summary,
            'highlights'      => ['Une correspondance a été confirmée automatiquement grâce au numéro.'],
            'structures'      => [['name' => 'Commissariat A', 'comment' => 'Activité de déclaration régulière.'], ['name' => 'Structure inventée', 'comment' => 'x']],
            'recommendations' => [['priority' => 'haute', 'text' => 'Renseigner le téléphone de chaque structure.']],
            'summary_en'      => 'One counter declaration and one deposit were recorded this month.',
        ], $extra), JSON_UNESCAPED_UNICODE)]]]];
    }

    private function monthly(): ActivityReport
    {
        $this->artisan('doctrack:monthly-reports', ['--month' => now()->format('Y-m')])->assertSuccessful();

        return ActivityReport::whereNull('organization_id')->sole();
    }

    public function test_narrative_waits_for_validation_then_the_report_is_sent(): void
    {
        $admin = $this->admin(['email' => 'admin@doctrack.sn']);
        Http::fake(['dashscope-intl.aliyuncs.com/*' => Http::response($this->answer(
            'Sur la période, 1 déclaration au guichet et 1 document déposé ont été enregistrés ; 1 correspondance a été détectée.'
        ))]);

        $report = $this->monthly();

        $this->assertSame(ActivityReport::NARRATIVE_PENDING, $report->narrative_status);
        $this->assertNull($report->delivered_at);                       // rien n'est parti
        $this->assertSame('qwen', $report->narrative['provider']);
        $this->assertSame(['Commissariat A'], array_column($report->narrative['structures'], 'name'));   // structure inventée écartée
        $this->assertSame(1, InboxMessage::where('event', 'report.narrative_pending')->where('subject_id', $report->id)->count());
        $this->assertSame(0, InboxMessage::where('event', 'report.monthly')->count());

        // Données envoyées : agrégats seulement, aucun nom de citoyen, ni téléphone, ni numéro.
        Http::assertSent(function (HttpRequest $req) {
            $body = $req->body();

            return $req->hasHeader('Authorization', 'Bearer qwen-key')
                && str_contains($body, 'qwen-plus')
                && ! str_contains($body, 'Ndiaye') && ! str_contains($body, '771234567') && ! str_contains($body, '1234567890123');
        });

        // Brouillon invisible des responsables d'institution tant qu'il n'est pas validé.
        $this->asUser($admin)->getJson("/api/v2/reports/{$report->uuid}")->assertOk()->assertJsonPath('narrative.provider', 'qwen');

        // Validation (avec une correction) → PDF/Excel refaits avec la synthèse, rapport envoyé.
        $this->asUser($admin)->postJson("/api/v2/reports/{$report->uuid}/narrative/approve", [
            'summary_fr' => 'Synthèse relue : 1 déclaration au guichet et 1 dépôt sur la période.',
            'note'       => 'Relu, RAS',
        ])->assertOk()->assertJsonPath('narrative_status', 'approved');

        $report->refresh();
        $this->assertNotNull($report->delivered_at);
        $this->assertTrue($report->narrative['edited_by_reviewer']);
        $this->assertSame(1, InboxMessage::where('event', 'report.monthly')->where('subject_id', $report->id)->count());

        $zip = new ZipArchive();
        $zip->open(PrivateFiles::path($report->xlsx_path));
        $this->assertStringContainsString('Commentaire', $zip->getFromName('xl/workbook.xml'));
        $sheets = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getFromIndex($i))->implode('');
        $this->assertStringContainsString('Synthèse relue', $sheets);
        $this->assertStringContainsString('One counter declaration', $sheets);

        // Déjà traitée.
        $this->asUser($admin)->postJson("/api/v2/reports/{$report->uuid}/narrative/approve")->assertStatus(409);
    }

    public function test_pending_narratives_can_be_listed_for_review(): void
    {
        $admin = $this->admin(['email' => 'admin@doctrack.sn']);
        Http::fake(['dashscope-intl.aliyuncs.com/*' => Http::response($this->answer(
            'Sur la période, 1 déclaration au guichet et 1 document déposé ont été enregistrés ; 1 correspondance a été détectée.'
        ))]);
        $report = $this->monthly();

        $this->asUser($admin)->getJson('/api/v2/reports?narrative_status=pending')
            ->assertOk()
            ->assertJsonPath('total', ActivityReport::where('narrative_status', ActivityReport::NARRATIVE_PENDING)->count())
            ->assertJsonFragment(['uuid' => $report->uuid, 'narrative_status' => 'pending']);
        $this->asUser($admin)->getJson('/api/v2/reports?narrative_status=approved')
            ->assertOk()->assertJsonPath('total', 0);
    }

    public function test_invented_numbers_are_rejected_and_the_next_provider_takes_over(): void
    {
        Http::fake([
            // Qwen invente un « 37 % » deux fois de suite.
            'dashscope-intl.aliyuncs.com/*' => Http::response($this->answer('Les restitutions ont progressé de 37 % sur la période.')),
            'api.z.ai/*'                    => Http::response($this->answer('La période compte 1 déclaration au guichet et 1 dépôt.')),
        ]);

        $report = $this->monthly();

        $this->assertSame(ActivityReport::NARRATIVE_PENDING, $report->narrative_status);
        $this->assertSame('glm', $report->narrative['provider']);
        $attempts = $report->narrative_attempts;
        $this->assertCount(3, $attempts);
        $this->assertStringContainsString('37', $attempts[0]['error']);
        $this->assertTrue($attempts[2]['ok']);

        // La 2e demande à Qwen signale le chiffre inventé.
        Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), 'dashscope') && str_contains($req->body(), 'ne figurent pas'));
    }

    public function test_without_valid_narrative_the_report_leaves_with_rules_only(): void
    {
        $this->admin();
        Http::fake([
            'dashscope-intl.aliyuncs.com/*' => Http::response('quota exceeded', 429),
            'api.z.ai/*'                    => Http::response(['choices' => [['message' => ['content' => 'Désolé, je ne peux pas.']]]]),
        ]);

        $report = $this->monthly();

        $this->assertSame(ActivityReport::NARRATIVE_FAILED, $report->narrative_status);
        $this->assertNull($report->narrative);
        $this->assertNotNull($report->delivered_at);                     // envoyé quand même, analyse par règles
        $this->assertSame(1, InboxMessage::where('event', 'report.monthly')->where('subject_id', $report->id)->count());
    }

    public function test_rejection_sends_the_rules_version_and_regeneration_is_possible(): void
    {
        $admin = $this->admin();
        Http::fake(['dashscope-intl.aliyuncs.com/*' => Http::response($this->answer('Période calme : 1 déclaration et 1 dépôt.'))]);
        $report = $this->monthly();

        $this->asUser($this->institutionAdmin($this->police))->postJson("/api/v2/reports/{$report->uuid}/narrative/reject", ['note' => 'Non'])->assertForbidden();
        $this->asUser($admin)->postJson("/api/v2/reports/{$report->uuid}/narrative/reject", ['note' => 'Ton trop vague'])
            ->assertOk()->assertJsonPath('narrative_status', 'rejected');
        $this->assertNotNull($report->fresh()->delivered_at);

        $this->asUser($admin)->postJson("/api/v2/reports/{$report->uuid}/narrative/regenerate")
            ->assertOk()->assertJsonPath('narrative_status', 'pending');
    }

    public function test_without_ai_nothing_changes(): void
    {
        config(['ai.reports.enabled' => false]);
        Http::fake();

        $report = $this->monthly();

        $this->assertSame(ActivityReport::NARRATIVE_NONE, $report->narrative_status);
        $this->assertNotNull($report->delivered_at);
        Http::assertNothingSent();
    }

    public function test_number_checker(): void
    {
        $n = app(ReportNarrator::class);
        $allowed = $n->allowedNumbers(['a' => 1234, 'b' => 18.46, 'c' => -12.5, 'label' => 'Août 2026', 'd' => null]);

        $ok = ['summary_fr' => 'Total 1 234 dossiers, en hausse de 18,5 % (18 %), baisse de 12,5 % ; moins de <3 cas ; août 2026 ; trois structures, 2 alertes.'];
        $this->assertSame([], $n->unverifiedNumbers($ok, $allowed));

        $bad = ['summary_fr' => 'Hausse de 25 % et 1 300 dossiers.', 'recommendations' => [['priority' => 'haute', 'text' => 'Viser 95 % en 2027.']]];
        $this->assertSame(['25', '1300', '95', '2027'], $n->unverifiedNumbers($bad, $allowed));
    }

    public function test_small_counts_are_masked_before_sending(): void
    {
        $payload = app(ReportNarrator::class)->payload([
            'structures' => [['uuid' => 'x', 'name' => 'Brigade Z', 'parent' => null, 'is_active' => true, 'restitutions' => 2, 'collected' => 7, 'contacted_within_sla_pct' => 1.5, 'median_first_contact_hours' => 2]],
            'by_region'  => [['name' => 'Kolda', 'count' => 1]],
        ], []);

        $this->assertSame('<3', $payload['structures'][0]['restitutions']);
        $this->assertSame(7, $payload['structures'][0]['collected']);
        $this->assertSame(1.5, $payload['structures'][0]['contacted_within_sla_pct']);
        $this->assertSame(2, $payload['structures'][0]['median_first_contact_hours']);   // une durée n'est pas un effectif
        $this->assertArrayNotHasKey('uuid', $payload['structures'][0]);
        $this->assertSame('<3', $payload['par_region'][0]['count']);
    }
}
