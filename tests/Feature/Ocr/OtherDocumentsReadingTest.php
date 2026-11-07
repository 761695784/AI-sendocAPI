<?php

namespace Tests\Feature\Ocr;

use App\Models\DocumentType;
use App\Services\Ocr\DocumentReader;
use App\Services\Ocr\MrzParser;
use Tests\TestCase;

/**
 * Lecture des pièces autres que la carte CEDEAO : passeport sénégalais, permis de conduire, carte grise.
 * Les fixtures sont de vraies lectures RapidOCR (même réglage que le service en ligne) de spécimens
 * officiels, avec des noms et numéros FICTIFS.
 */
class OtherDocumentsReadingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (["Carte nationale d'identité", 'Passeport', 'Permis de conduire', 'Carte grise'] as $name) {
            DocumentType::factory()->create(['TypeName' => $name]);
        }
    }

    private function read(string $fixture): array
    {
        $ocr = json_decode(file_get_contents(base_path("tests/Fixtures/ocr/{$fixture}.json")), true);

        return app(DocumentReader::class)->read($ocr);
    }

    private function values(array $result): array
    {
        return array_map(fn ($f) => $f['value'], $result['fields']);
    }

    public function test_senegalese_passport_mrz_with_a_single_chevron_on_line_two(): void
    {
        $r = $this->read('passeport_sn_specimen');

        $this->assertSame('Passeport', $r['document_type']['name']);
        $this->assertTrue($r['mrz']['valid']);
        $this->assertSame('Fall', $r['fields']['last_name']['value']);
        $this->assertSame('Ibou Khadim', $r['fields']['first_name']['value']);
        $this->assertSame('A00000000', $r['fields']['doc_number']['value']);
        $this->assertSame('1951-12-13', $r['fields']['birth_date']['value']);
    }

    public function test_passport_mrz_fillers_read_as_c_are_ignored(): void
    {
        $r = $this->read('passeport_sn_mrz_bruitee');

        $this->assertTrue($r['mrz']['valid']);
        $this->assertSame('Ibou Khadim', $r['fields']['first_name']['value']);

        $mrz = (new MrzParser())->parse(['P<SENFALL<<IBOU<<<C<<<<<C<<<<<C<<<CCCC<C<C<<<', 'A000000000SEN5112139M12122601662195100161<12']);
        $this->assertSame('IBOU', $mrz['given_names']);
    }

    public function test_passport_without_mrz_uses_the_page_layout_not_the_nationality(): void
    {
        $r = $this->read('passeport_sn_sans_mrz');

        $this->assertFalse($r['mrz']['present']);
        $this->assertSame('Fall', $r['fields']['last_name']['value']);
        $this->assertNotSame('Senegalaise', $r['fields']['first_name']['value'] ?? null);
        $this->assertSame('A00000000', $r['fields']['doc_number']['value']);
        $this->assertSame('1951-12-13', $r['fields']['birth_date']['value']);
    }

    public function test_driving_licence_numbered_fields(): void
    {
        $r = $this->read('permis_sn_fictif');

        $this->assertSame('Permis de conduire', $r['document_type']['name']);
        $this->assertSame(['first_name' => 'Awa Marie', 'last_name' => 'Ndiaye', 'doc_number' => '12345678', 'birth_date' => '1990-03-12'],
            array_intersect_key($this->values($r), array_flip(['first_name', 'last_name', 'doc_number', 'birth_date'])));
        $this->assertNotEmpty($r['sensitive_lines']);   // numéro et dates masqués
    }

    public function test_vehicle_registration_holder_and_plate(): void
    {
        $r = $this->read('carte_grise_fictive');

        $this->assertSame('Carte grise', $r['document_type']['name']);
        $this->assertSame('Diop', $r['fields']['last_name']['value']);
        $this->assertSame('Moussa', $r['fields']['first_name']['value']);
        $this->assertSame('AA123BC', $r['fields']['doc_number']['value']);
    }

    public function test_user_is_told_when_names_could_not_be_read(): void
    {
        $r = app(DocumentReader::class)->read(['lines' => [
            ['text' => 'PERMIS DE CONDUIRE', 'confidence' => 0.99, 'box' => [[10, 10], [200, 10], [200, 30], [10, 30]]],
            ['text' => '5. N° Permis', 'confidence' => 0.99, 'box' => [[10, 60], [100, 60], [100, 75], [10, 75]]],
            ['text' => '12345678', 'confidence' => 0.99, 'box' => [[130, 60], [200, 60], [200, 75], [130, 75]]],
            ['text' => 'SPECIMEN', 'confidence' => 0.99, 'box' => [[10, 90], [100, 90], [100, 105], [10, 105]]],
            ['text' => 'SIGNATURE', 'confidence' => 0.99, 'box' => [[10, 130], [100, 130], [100, 145], [10, 145]]],
        ]]);

        $this->assertContains('names_not_read', array_column($r['checks'], 'code'));
        $this->assertSame('12345678', $r['fields']['doc_number']['value']);
    }
}
