<?php

namespace Tests\Feature\Ocr;

use App\Jobs\SendSmsJob;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\OcrScan;
use App\Models\Restitution;
use App\Models\User;
use App\Models\VigilanceAlert;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Le même scan au guichet : dépôt d'un document trouvé par un agent, puis
 * contrôle de la pièce présentée par la personne qui vient le récupérer.
 */
class CounterAndRestitutionScanTest extends OrganizationTestCase
{
    private DocumentType $cni;

    private User $agentB;

    /** Réponse que renverra le service OCR simulé au prochain scan. */
    private array $nextOcr = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Bus::fake([SendSmsJob::class]);
        $this->cni = DocumentType::factory()->create(['TypeName' => "Carte nationale d'identité"]);
        DocumentType::factory()->create(['TypeName' => 'Passeport']);
        $this->agentB = $this->agent($this->commissariatB);
        config(['ai.ocr.provider' => 'hf_space', 'ai.ocr.hf_space.url' => 'https://doctrack-ocr.hf.space']);
        Http::fake(['doctrack-ocr.hf.space/ocr' => fn () => Http::response($this->nextOcr)]);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/ocr/{$name}")), true);
    }

    /** Réponse OCR simulée : lignes « libellé » puis « valeur » les unes sous les autres. */
    private function ocrLines(array $pairs, array $extra = []): array
    {
        $lines = [];
        $y = 20;
        foreach ($extra as $text) {
            $lines[] = ['text' => $text, 'confidence' => 0.98, 'box' => [[20, $y], [600, $y], [600, $y + 28], [20, $y + 28]]];
            $y += 40;
        }
        foreach ($pairs as $label => $value) {
            $lines[] = ['text' => $label, 'confidence' => 0.97, 'box' => [[300, $y], [560, $y], [560, $y + 20], [300, $y + 20]]];
            $lines[] = ['text' => $value, 'confidence' => 0.99, 'box' => [[300, $y + 24], [520, $y + 24], [520, $y + 52], [300, $y + 52]]];
            $y += 72;
        }

        return ['engine' => 'test', 'rotation' => 0, 'width' => 1011, 'height' => 638, 'lines' => $lines];
    }

    private function scan(User $user, array $ocr, string $purpose, string $image = 'cni_recto.jpg')
    {
        $this->nextOcr = $ocr;
        $tmp = tempnam(sys_get_temp_dir(), 'ocr') . '.jpg';
        copy(base_path("tests/Fixtures/ocr/{$image}"), $tmp);

        return $this->asUser($user)->post('/api/v2/ocr/scan', [
            'image'   => new UploadedFile($tmp, $image, 'image/jpeg', null, true),
            'purpose' => $purpose,
        ], ['Accept' => 'application/json']);
    }

    /** Dépôt au guichet d'une CNI scannée (Awa Ndiaye, spécimen). */
    private function depositScanned(): Document
    {
        $scan = $this->scan($this->agentB, $this->fixture('cni_recto.jpg.json'), 'found_document')->assertCreated();

        $uuid = $this->asUser($this->agentB)->postJson('/api/v2/found-documents', [
            'ocr_scan_id'      => $scan->json('scan_id'),
            'document_type_id' => $this->cni->id,
            'first_name'       => $scan->json('prefill.OwnerFirstName'),
            'last_name'        => $scan->json('prefill.OwnerLastName'),
            'birth_date'       => $scan->json('prefill.birth_date'),
            'doc_number'       => $scan->json('prefill.DocIdentification'),
            'found_place'      => 'Gare routière',
        ])->assertCreated()->json('uuid');

        return Document::where('uuid', $uuid)->firstOrFail();
    }

    private function awaPassport(): array
    {
        return $this->ocrLines([
            'Nom / Surname'                   => 'NDIAYE',
            'Prénoms / Given names'           => 'AWA',
            'Date de naissance / Date of birth' => '12 MAY 1985',
            'N° du passeport / Passport No'   => 'A07654321',
        ], ['RÉPUBLIQUE DU SÉNÉGAL — PASSEPORT / PASSPORT']);
    }

    public function test_agent_deposits_a_scanned_document(): void
    {
        $document = $this->depositScanned();

        $this->assertSame(Document::LIFECYCLE_IN_CUSTODY, $document->lifecycle_status);
        $this->assertSame($this->commissariatB->id, $document->custody_organization_id);
        $this->assertSame('1237', $document->doc_number_last4);
        $this->assertSame('masked', $document->getFirstMedia('document_image')->getCustomProperty('public_version'));
        $this->assertNotNull($document->getFirstMedia('document_public'));
        $this->assertSame(OcrScan::STATUS_USED, OcrScan::sole()->status);
    }

    public function test_presented_id_is_read_and_checked_without_keeping_the_photo(): void
    {
        $document = $this->depositScanned();

        $scan = $this->scan($this->agentB, $this->awaPassport(), 'identity_check')->assertCreated()
            ->assertJsonPath('purpose', 'identity_check')
            ->assertJsonPath('prefill.id_type', 'passeport')
            ->assertJsonPath('prefill.id_number', 'A07654321')
            ->assertJsonPath('prefill.birth_date', '1985-05-12')
            ->assertJsonPath('preview_url', null);
        $this->assertSame([], Storage::disk('local')->files('ocr/scans'));   // aucune photo de la pièce présentée

        $this->asUser($this->agentB)->postJson("/api/v2/found-documents/{$document->uuid}/restitution", [
            'recipient_type' => 'owner',
            'owner'          => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 123 45 67', 'ocr_scan_id' => $scan->json('scan_id')],
            'checks'         => ['identity_photo_matches' => true],
        ])->assertCreated()
            ->assertJsonPath('owner.id_last4', '4321');

        $restitution = Restitution::sole();
        $this->assertSame('passeport', $restitution->owner_id_type);
        $this->assertEquals(1.0, $restitution->checks['id_scans']['owner']['name_similarity']);
        $this->assertSame('Passeport', $restitution->checks['id_scans']['owner']['type']);

        $used = OcrScan::where('purpose', 'identity_check')->sole();
        $this->assertSame(OcrScan::STATUS_USED, $used->status);
        $this->assertNull($used->result);   // plus rien de la pièce présentée
    }

    public function test_presented_id_in_another_name_is_refused(): void
    {
        $document = $this->depositScanned();
        $scan = $this->scan($this->agentB, $this->fixture('passeport.jpg.json'), 'identity_check');   // Moussa Ibrahima Diop

        $this->asUser($this->agentB)->postJson("/api/v2/found-documents/{$document->uuid}/restitution", [
            'recipient_type' => 'owner',
            'owner'          => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'ocr_scan_id' => $scan->json('scan_id')],
            'checks'         => ['identity_photo_matches' => true],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('owner.ocr_scan_id');

        $this->assertStringContainsString('Diop', $this->asUser($this->agentB)->postJson("/api/v2/found-documents/{$document->uuid}/restitution", [
            'recipient_type' => 'owner',
            'owner'          => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'ocr_scan_id' => $scan->json('scan_id')],
            'checks'         => ['identity_photo_matches' => true],
        ])->json('errors')['owner.ocr_scan_id'][0]);
        $this->assertSame(0, Restitution::count());
    }

    public function test_presenting_the_document_itself_raises_a_high_alert_and_locks_the_handover(): void
    {
        $document = $this->depositScanned();
        // La « pièce présentée » est… le verso de la carte que la structure détient.
        $scan = $this->scan($this->agentB, $this->fixture('cni_verso.jpg.json'), 'identity_check', 'cni_verso.jpg');

        $this->asUser($this->agentB)->postJson("/api/v2/found-documents/{$document->uuid}/restitution", [
            'recipient_type' => 'owner',
            'owner'          => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'ocr_scan_id' => $scan->json('scan_id')],
            'checks'         => ['identity_photo_matches' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors('owner.ocr_scan_id');

        $alert = VigilanceAlert::where('kind', 'presented_same_document')->sole();
        $this->assertSame(VigilanceAlert::LEVEL_HIGH, $alert->level);
        $this->assertSame($this->commissariatB->id, $alert->organization_id);

        // Tant que l'alerte est ouverte : accord motivé d'un responsable.
        $manual = [
            'recipient_type' => 'owner',
            'owner'          => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'id_type' => 'passeport', 'id_number' => 'A07654321'],
            'checks'         => ['identity_photo_matches' => true],
        ];
        $this->asUser($this->agentB)->postJson("/api/v2/found-documents/{$document->uuid}/restitution", $manual)
            ->assertUnprocessable()->assertJsonValidationErrors('vigilance_override_reason');
        $this->asUser($this->agentB)->postJson("/api/v2/found-documents/{$document->uuid}/restitution", $manual + ['vigilance_override_reason' => 'Vérifié avec le fichier'])
            ->assertUnprocessable()->assertJsonValidationErrors('vigilance_override_reason');

        $manager = $this->manager($this->commissariatB);
        $this->asUser($manager)->postJson("/api/v2/found-documents/{$document->uuid}/restitution", $manual + ['vigilance_override_reason' => 'Identité vérifiée avec le passeport original'])
            ->assertCreated();
        $this->assertSame($alert->uuid, Restitution::sole()->checks['vigilance_override']['alert']);
    }

    public function test_a_scan_belongs_to_the_agent_who_made_it(): void
    {
        $document = $this->depositScanned();
        $scan = $this->scan($this->agent($this->commissariatB), $this->awaPassport(), 'identity_check');

        $this->asUser($this->agentB)->postJson("/api/v2/found-documents/{$document->uuid}/restitution", [
            'recipient_type' => 'owner',
            'owner'          => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'ocr_scan_id' => $scan->json('scan_id')],
            'checks'         => ['identity_photo_matches' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors('owner.ocr_scan_id');
    }
}
