<?php

namespace Tests\Feature\Reports;

use App\Jobs\SendSmsJob;
use App\Models\ActivityReport;
use App\Models\DocumentType;
use App\Models\InboxMessage;
use App\Models\NotificationDelivery;
use App\Models\Territory;
use App\Support\PrivateFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;
use ZipArchive;

/**
 * Rapports d'activité V2 : par périmètre, PDF + Excel, génération mensuelle automatique.
 */
class ActivityReportTest extends OrganizationTestCase
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
    }

    private function activity(): void
    {
        $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/declarations', [
            'citizen' => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 123 45 67'],
            'items'   => [['document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye', 'doc_number' => '1234567890123']],
        ])->assertCreated();
        $this->asUser($this->agent($this->commissariatB))->postJson('/api/v2/found-documents', [
            'document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye', 'doc_number' => '1234567890123',
        ])->assertCreated();
    }

    public function test_manager_generates_a_report_for_his_structure_with_pdf_and_excel(): void
    {
        $this->activity();
        $manager = $this->manager($this->commissariatA);

        $r = $this->asUser($manager)->postJson('/api/v2/reports', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('scope.uuid', $this->commissariatA->uuid)
            ->assertJsonPath('data.overview.declarations.counter', 1)
            ->assertJsonPath('data.overview.found_documents.entered_custody', 0); // le dépôt est chez B
        $this->assertMatchesRegularExpression('/^RP-\d{4}-\d{6}$/', $r->json('reference'));
        $this->assertNotEmpty($r->json('analysis.summary'));

        $report = ActivityReport::sole();
        $this->assertTrue(PrivateFiles::exists($report->pdf_path));

        $this->asUser($manager)->get("/api/v2/reports/{$report->uuid}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $xlsx = $this->asUser($manager)->get("/api/v2/reports/{$report->uuid}/xlsx")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('Content-Type'));

        // Le classeur contient bien les feuilles attendues.
        $zip = new ZipArchive();
        $zip->open(PrivateFiles::path($report->xlsx_path));
        $workbook = $zip->getFromName('xl/workbook.xml');
        foreach (['Synthèse', 'Structures', 'Évolution', 'Types de documents', 'Régions', 'Finances', 'Analyse'] as $sheet) {
            $this->assertStringContainsString($sheet, $workbook);
        }

        // Une autre structure n'y a pas accès ; un agent ne génère pas de rapport.
        $this->asUser($this->manager($this->commissariatB))->getJson("/api/v2/reports/{$report->uuid}")->assertForbidden();
        $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/reports')->assertForbidden();
    }

    public function test_institution_report_covers_all_its_structures(): void
    {
        $this->activity();
        $admin = $this->institutionAdmin($this->police);

        $this->asUser($admin)->postJson('/api/v2/reports', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('scope.uuid', $this->police->uuid)
            ->assertJsonPath('data.overview.declarations.counter', 1)
            ->assertJsonPath('data.overview.found_documents.entered_custody', 1)
            ->assertJsonPath('data.overview.matches.auto_confirmed', 1)
            ->assertJsonCount(2, 'data.structures');

        $this->asUser($admin)->postJson('/api/v2/reports', ['organization' => $this->brigadeZ->uuid])->assertForbidden();
        $this->asUser($admin)->postJson('/api/v2/reports', ['organization' => $this->direction->uuid, 'month' => now()->format('Y-m')])->assertCreated();
        $this->asUser($admin)->getJson('/api/v2/reports')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_analysis_flags_late_collections(): void
    {
        $dakar = Territory::where('code', 'SN-DK-DAKAR')->value('id');
        $this->commissariatB->update(['territory_id' => $dakar, 'latitude' => 14.67, 'longitude' => -17.438]);
        $this->asUser($this->citizen())->post('/api/documents', [
            'image' => UploadedFile::fake()->image('cni.jpg'), 'OwnerFirstName' => 'Moussa', 'OwnerLastName' => 'Sarr',
            'Location' => 'Sandaga', 'document_type_id' => $this->cni->id, 'contact_consent' => true,
            'contact_phone' => '78 555 44 33', 'latitude' => 14.672, 'longitude' => -17.44,
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->travel(30)->hours();

        $analysis = $this->asUser($this->admin())->postJson('/api/v2/reports', [
            'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(),
        ])->assertCreated()->json('analysis');

        $this->assertNotEmpty(collect($analysis['alerts'])->filter(fn ($a) => str_contains($a['message'], 'premier appel')));
        $this->assertTrue(collect($analysis['recommendations'])->contains(fn ($r) => str_contains($r, 'Commissariat B')));
        $this->assertLessThan(100, $analysis['score']);
    }

    public function test_monthly_reports_are_generated_and_delivered(): void
    {
        $this->institutionAdmin($this->police)->update(['email' => 'chef.police@example.sn']);
        $this->admin();

        $this->artisan('doctrack:monthly-reports', ['--month' => now()->subMonth()->format('Y-m')])->assertSuccessful();

        // National + Police + Gendarmerie.
        $this->assertSame(3, ActivityReport::where('automatic', true)->count());
        $this->assertSame(1, ActivityReport::whereNull('organization_id')->count());

        $this->assertTrue(InboxMessage::where('event', 'report.monthly')->where('organization_id', $this->police->id)->exists());
        $this->assertTrue(InboxMessage::where('event', 'report.monthly')->whereNotNull('user_id')->exists()); // super admin
        $this->assertTrue(NotificationDelivery::where('event', 'report.monthly')->where('channel', 'email')->where('destination_masked', 'c•••@example.sn')->exists());

        // Relancer la même période remplace les rapports (pas de doublon).
        $this->artisan('doctrack:monthly-reports', ['--month' => now()->subMonth()->format('Y-m')])->assertSuccessful();
        $this->assertSame(3, ActivityReport::count());
    }

    public function test_super_admin_sees_national_reports_and_can_delete(): void
    {
        $admin = $this->admin();
        $uuid  = $this->asUser($admin)->postJson('/api/v2/reports', ['year' => now()->year - 1])->assertCreated()
            ->assertJsonPath('scope', 'national')
            ->assertJsonPath('period.label', 'Année ' . (now()->year - 1))
            ->json('uuid');

        $this->asUser($admin)->getJson('/api/v2/reports?national=1')->assertJsonCount(1, 'data');
        $this->asUser($this->institutionAdmin($this->police))->getJson("/api/v2/reports/{$uuid}")->assertForbidden();
        $this->asUser($this->institutionAdmin($this->police))->deleteJson("/api/v2/reports/{$uuid}")->assertForbidden();
        $this->asUser($admin)->deleteJson("/api/v2/reports/{$uuid}")->assertOk();
        $this->assertSame(0, ActivityReport::count());
    }
}
