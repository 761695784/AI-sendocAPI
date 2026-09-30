<?php

namespace Tests\Feature\Ocr;

use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\DocumentNumber;
use App\Models\DocumentType;
use App\Models\OcrScan;
use App\Models\User;
use App\Services\Ocr\DocumentReader;
use App\Services\Ocr\MrzParser;
use App\Services\Ocr\SenegalIdNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Scan OCR d'une pièce trouvée. Les réponses du service OCR sont de vraies
 * lectures (RapidOCR) de spécimens FICTIFS générés par
 * ai/doctrack-ocr/specimens/make_specimens.py.
 */
class OcrScanTest extends TestCase
{
    private DocumentType $cni;

    private DocumentType $passeport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cni       = DocumentType::factory()->create(['TypeName' => "Carte nationale d'identité"]);
        $this->passeport = DocumentType::factory()->create(['TypeName' => 'Passeport']);
        config([
            'ai.ocr.provider'         => 'hf_space',
            'ai.ocr.hf_space.url'     => 'https://doctrack-ocr.hf.space',
            'ai.ocr.hf_space.token'   => 'secret',
        ]);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/ocr/{$name}")), true);
    }

    private function fakeOcr(string $name): void
    {
        Http::fake(['doctrack-ocr.hf.space/ocr' => Http::response($this->fixture($name))]);
    }

    private function image(string $name): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ocr') . '.jpg';
        copy(base_path("tests/Fixtures/ocr/{$name}"), $tmp);

        return new UploadedFile($tmp, $name, 'image/jpeg', null, true);
    }

    private function scanAs(User $user, string $name)
    {
        $this->fakeOcr($name . '.json');

        return $this->asUser($user)->post('/api/v2/ocr/scan', ['image' => $this->image($name)], ['Accept' => 'application/json']);
    }

    // ── Règles de lecture (sans réseau) ──────────────────────────────

    public function test_mrz_of_the_ecowas_card_is_parsed_and_checked(): void
    {
        $mrz = (new MrzParser())->parse(['IDSEN201198505<120012377<<<<<<', '8505121F3103119SEN<<<<<<<<<<<6', 'NDIAYE<<AWA<<<<<<<<<<<<<<<<<<<']);

        $this->assertTrue($mrz['valid']);
        $this->assertSame('TD1', $mrz['format']);
        $this->assertSame('20119850512001237', $mrz['document_number']);   // numéro long, règle OACI
        $this->assertSame('1985-05-12', $mrz['birth_date']);
        $this->assertSame('2031-03-11', $mrz['expiry_date']);
        $this->assertSame(['NDIAYE', 'AWA'], [$mrz['surname'], $mrz['given_names']]);
    }

    public function test_mrz_tolerates_ocr_noise_but_detects_tampering(): void
    {
        $parser = new MrzParser();

        $noisy = $parser->parse(['IDSEN2O1198505 <12OO12377«<<<<', '85O5121F3103119SEN<<<<<<<<<<<6', 'NDIAYE<<AWA<<<KKKKKKK<<<<<<<<<']);
        $this->assertTrue($noisy['valid']);
        $this->assertSame('20119850512001237', $noisy['document_number']);
        $this->assertSame('AWA', $noisy['given_names']);

        $tampered = $parser->parse(['IDSEN201198505<120012378<<<<<<', '8505121F3103119SEN<<<<<<<<<<<6', 'NDIAYE<<AWA<<<<<<<<<<<<<<<<<<<']);
        $this->assertFalse($tampered['valid']);
        $this->assertFalse($tampered['checks']['document_number']);

        $passport = $parser->parse(['P<SENDIOP<<MOUSSA<IBRAHIMA<<<<<<<<<<<<<<<<<<', 'A012345678SEN9001033M2902142<<<<<<<<<<<<<<02']);
        $this->assertTrue($passport['valid']);
        $this->assertSame('A01234567', $passport['document_number']);
        $this->assertSame('MOUSSA IBRAHIMA', $passport['given_names']);
    }

    public function test_ecowas_card_number_structure(): void
    {
        $ok = SenegalIdNumber::analyze('2 01 19850512 00123 7');
        $this->assertTrue($ok['structure_ok']);
        $this->assertSame('F', $ok['sex']);
        $this->assertSame('1985-05-12', $ok['birth_date']);

        $this->assertFalse(SenegalIdNumber::analyze('30119850512001237')['structure_ok']);   // 3 = sexe inconnu
        $this->assertFalse(SenegalIdNumber::analyze('20119851512001237')['structure_ok']);   // 15e mois
        $this->assertFalse(SenegalIdNumber::analyze('201198505120012')['structure_ok']);     // trop court
    }

    public function test_reader_understands_front_back_passport_and_crooked_photos(): void
    {
        $reader = app(DocumentReader::class);

        $front = $reader->read($this->fixture('cni_recto.jpg.json'));
        $this->assertSame($this->cni->id, $front['document_type']['id']);
        $this->assertSame('Awa', $front['fields']['first_name']['value']);
        $this->assertSame('Ndiaye', $front['fields']['last_name']['value']);
        $this->assertSame('20119850512001237', $front['fields']['doc_number']['value']);
        $this->assertSame('1985-05-12', $front['fields']['birth_date']['value']);
        $this->assertSame('F', $front['fields']['sex']['value']);
        $this->assertSame('read', $front['quality']['status']);
        $this->assertContains('number_structure_ok', array_column($front['checks'], 'code'));
        $this->assertCount(3, $front['sensitive_lines']);   // numéro + dates

        $back = $reader->read($this->fixture('cni_verso.jpg.json'));
        $this->assertTrue($back['mrz']['valid']);
        $this->assertSame('mrz', $back['fields']['last_name']['source']);
        $this->assertSame(['card_number', 'nin'], array_column($back['numbers'], 'kind'));
        $this->assertSame('2750198501234', $back['numbers'][1]['value']);

        $pass = $reader->read($this->fixture('passeport.jpg.json'));
        $this->assertSame($this->passeport->id, $pass['document_type']['id']);
        $this->assertSame('Moussa Ibrahima', $pass['fields']['first_name']['value']);
        $this->assertSame('A01234567', $pass['fields']['doc_number']['value']);

        foreach (['cni_photo_inclinee.jpg.json', 'cni_photo_tournee.jpg.json'] as $crooked) {
            $r = $reader->read($this->fixture($crooked));
            $this->assertSame('Ndiaye', $r['fields']['last_name']['value'], $crooked);
            $this->assertSame('20119850512001237', $r['fields']['doc_number']['value'], $crooked);
        }
    }

    public function test_inconsistent_card_is_flagged(): void
    {
        $fixture = $this->fixture('cni_recto.jpg.json');
        foreach ($fixture['lines'] as &$line) {
            if ($line['text'] === '12/05/1985') {
                $line['text'] = '12/05/1990';     // date modifiée : ne correspond plus au numéro
            }
        }

        $r = app(DocumentReader::class)->read($fixture);

        $this->assertContains('birth_date_mismatch', array_column($r['checks'], 'code'));
        $this->assertContains('birth_date_mismatch', $r['quality']['issues']);
    }

    // ── API ──────────────────────────────────────────────────────────

    public function test_scan_proposes_fields_and_masks_the_public_photo(): void
    {
        $user = $this->citizen();

        $r = $this->scanAs($user, 'cni_recto.jpg')->assertCreated();

        $r->assertJsonPath('status', 'read')
            ->assertJsonPath('document_type.id', $this->cni->id)
            ->assertJsonPath('prefill.OwnerFirstName', 'Awa')
            ->assertJsonPath('prefill.OwnerLastName', 'Ndiaye')
            ->assertJsonPath('prefill.DocIdentification', '20119850512001237')
            ->assertJsonPath('masked_regions', 3);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer secret') && str_ends_with($req->url(), '/ocr'));

        // Le résultat (numéros) est chiffré en base.
        $raw = DB::table('ocr_scans')->value('result');
        $this->assertStringNotContainsString('20119850512001237', $raw);

        // Aperçu public : la ligne du numéro est recouverte d'un bandeau sombre.
        $preview = $this->get($r->json('preview_url'))->assertOk();
        $img = imagecreatefromstring(file_get_contents($preview->baseResponse->getFile()->getPathname()));
        $numberBox = collect($this->fixture('cni_recto.jpg.json')['lines'])->firstWhere('text', '20119850512001237')['box'];
        $rgb = imagecolorat($img, (int) (($numberBox[0][0] + $numberBox[1][0]) / 2), (int) (($numberBox[0][1] + $numberBox[2][1]) / 2));
        $this->assertLessThan(60, ($rgb >> 16) & 0xFF);
        $this->assertLessThan(60, $rgb & 0xFF);

        // Sans signature, pas d'aperçu.
        $this->get("/api/v2/ocr/scans/{$r->json('scan_id')}/preview")->assertForbidden();
    }

    public function test_publication_from_a_scan_keeps_numbers_and_publishes_only_the_masked_photo(): void
    {
        $finder = $this->citizen();
        $scan = $this->scanAs($finder, 'cni_verso.jpg')->assertCreated();

        $this->asUser($finder)->post('/api/documents', [
            'ocr_scan_id'       => $scan->json('scan_id'),
            'OwnerFirstName'    => 'Awa',
            'OwnerLastName'     => 'Ndiaye',
            'DocIdentification' => $scan->json('prefill.DocIdentification'),
            'document_type_id'  => $this->cni->id,
            'Location'          => 'Marché Sandaga',
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = Document::sole();
        $this->assertSame('1237', $document->doc_number_last4);
        $this->assertSame('1985-05-12', $document->birth_date_in_doc->toDateString());

        // Original privé, sans version publique ; copie masquée avec versions publiques.
        $original = $document->getFirstMedia('document_image');
        $public   = $document->getFirstMedia('document_public');
        $this->assertSame('masked', $original->getCustomProperty('public_version'));
        $this->assertFalse($original->hasGeneratedConversion('blurred'));
        $this->assertTrue($public->hasGeneratedConversion('blurred'));
        // Le public (visiteur) ne voit que la version floutée de la copie masquée.
        $shown = $this->getJson("/api/documents/{$document->uuid}")->assertOk()->json('image_blurred');
        $this->assertStringContainsString('/' . $public->id . '/', $shown);

        // NIN lu au dos : enregistré (empreinte) pour les correspondances.
        $this->assertSame(['nin'], DocumentNumber::where('document_id', $document->id)->pluck('kind')->all());

        $used = OcrScan::sole();
        $this->assertSame(OcrScan::STATUS_USED, $used->status);
        $this->assertNull($used->image_path);
        $this->assertSame([], Storage::disk('local')->files('ocr/scans'));
        $this->assertSame([], $used->corrections);
        $this->assertNotNull(Activity::where('description', 'Pièce lue par OCR')->first());
    }

    public function test_declaration_with_the_nin_matches_the_scanned_card(): void
    {
        $owner = $this->citizen();
        $this->asUser($owner)->postJson('/api/declarations', [
            'Title'             => 'Perte de CNI',
            'FirstNameInDoc'    => 'Awa',
            'LastNameInDoc'     => 'Ndiaye',
            'DocIdentification' => '2 750 1985 01234',     // la victime connaît son NIN, pas le numéro de carte
            'document_type_id'  => $this->cni->id,
        ])->assertCreated();

        $finder = $this->citizen();
        $scan = $this->scanAs($finder, 'cni_verso.jpg');
        $this->asUser($finder)->post('/api/documents', [
            'ocr_scan_id'       => $scan->json('scan_id'),
            'OwnerFirstName'    => 'Awa',
            'OwnerLastName'     => 'Ndiaye',
            'DocIdentification' => '20119850512001237',
            'document_type_id'  => $this->cni->id,
            'Location'          => 'Dakar',
        ], ['Accept' => 'application/json'])->assertCreated();

        $match = DocumentMatch::where('declaration_de_perte_id', DeclarationDePerte::sole()->id)->sole();
        $this->assertTrue($match->number_match);
        $this->assertSame(DocumentMatch::STATUS_CONFIRMED, $match->status);
    }

    public function test_corrections_are_recorded(): void
    {
        $finder = $this->citizen();
        $scan = $this->scanAs($finder, 'cni_recto.jpg');

        $this->asUser($finder)->post('/api/documents', [
            'ocr_scan_id'      => $scan->json('scan_id'),
            'OwnerFirstName'   => 'Awa Marie',
            'OwnerLastName'    => 'Ndiaye',
            'document_type_id' => $this->cni->id,
            'Location'         => 'Dakar',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(['first_name'], OcrScan::sole()->corrections);
    }

    public function test_a_scan_cannot_be_reused_or_used_by_someone_else(): void
    {
        $finder = $this->citizen();
        $scan = $this->scanAs($finder, 'cni_recto.jpg');
        $payload = ['ocr_scan_id' => $scan->json('scan_id'), 'OwnerFirstName' => 'Awa', 'OwnerLastName' => 'Ndiaye', 'document_type_id' => $this->cni->id, 'Location' => 'Dakar'];

        $this->asUser($this->citizen())->post('/api/documents', $payload, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('ocr_scan_id');
        $this->asUser($this->citizen())->getJson("/api/v2/ocr/scans/{$scan->json('scan_id')}")->assertNotFound();

        $this->asUser($finder)->post('/api/documents', $payload, ['Accept' => 'application/json'])->assertCreated();
        $this->asUser($finder)->post('/api/documents', $payload, ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_scan_is_unavailable_without_provider(): void
    {
        config(['ai.ocr.provider' => 'none']);

        $this->asUser($this->citizen())->post('/api/v2/ocr/scan', ['image' => $this->image('cni_recto.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(503);
        $this->post('/api/v2/ocr/scan', [], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_service_errors_leave_nothing_behind(): void
    {
        Http::fake(['doctrack-ocr.hf.space/ocr' => Http::response('boom', 500)]);

        $this->asUser($this->citizen())->post('/api/v2/ocr/scan', ['image' => $this->image('cni_recto.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(503);

        $this->assertSame(0, OcrScan::count());
        $this->assertSame([], Storage::disk('local')->files('ocr/scans'));
    }

    public function test_unpublished_scans_are_purged(): void
    {
        $user = $this->citizen();
        $this->scanAs($user, 'cni_recto.jpg')->assertCreated();
        OcrScan::query()->update(['expires_at' => now()->subMinute()]);

        $this->artisan('doctrack:ocr-purge')->assertSuccessful();

        $this->assertSame(0, OcrScan::count());
        $this->assertSame([], Storage::disk('local')->files('ocr/scans'));
    }
}
