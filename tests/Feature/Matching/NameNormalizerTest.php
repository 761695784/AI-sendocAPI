<?php

namespace Tests\Feature\Matching;

use App\Services\Matching\JaroWinkler;
use App\Services\Matching\NameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NameNormalizerTest extends TestCase
{
    private NameNormalizer $names;

    protected function setUp(): void
    {
        parent::setUp();
        $this->names = app(NameNormalizer::class);
    }

    public function test_normalization_removes_accents_case_and_punctuation(): void
    {
        $this->assertSame('ndiaye', $this->names->normalize("  N'DIAYÉ "));
        $this->assertSame('mame diarra', $this->names->normalize('Mame-Diarra'));
        $this->assertSame('', $this->names->normalize(null));
    }

    public static function sameKey(): array
    {
        return [
            'Ndiaye / Njaay'          => ['Ndiaye', 'Njaay'],
            'Ndiaye / Ndiay'          => ['Ndiaye', 'Ndiay'],
            'Mouhamadou / Mohamed'    => ['Mouhamadou', 'Mohamed'],
            'Mamadou / Modou'         => ['Mamadou', 'Modou'],
            'Diop / Joop'             => ['Diop', 'Joop'],
            'Fatou / Fatoumata'       => ['Fatou', 'Fatoumata'],
            'Cissé / Sisse'           => ['Cissé', 'Sisse'],
            'Thiam / Tiam'            => ['Thiam', 'Tiam'],
            'Ba / Bah'                => ['Ba', 'Bah'],
            "N'Diaye / Ndiaye"        => ["N'Diaye", 'Ndiaye'],
            "M'Bengue / Mbengue"      => ["M’Bengue", 'Mbengue'],
            'Aïssatou / Aissatou'     => ['Aïssatou', 'AISSATOU'],
            'ordre des mots composés' => ['Abdou Aziz', 'Aziz Abdou'],
        ];
    }

    #[DataProvider('sameKey')]
    public function test_senegalese_variants_share_a_phonetic_key(string $a, string $b): void
    {
        $this->assertSame($this->names->key($a), $this->names->key($b));
    }

    public function test_different_names_have_different_keys(): void
    {
        $this->assertNotSame($this->names->key('Diallo'), $this->names->key('Diop'));
        $this->assertNotSame($this->names->key('Sow'), $this->names->key('Seck'));
        $this->assertNotSame($this->names->key('Awa'), $this->names->key('Aminata'));
    }

    public function test_similarity_scale(): void
    {
        $this->assertSame(1.0, $this->names->similarity('Ndiaye', 'NDIAYE'));
        $this->assertGreaterThanOrEqual(0.95, $this->names->similarity('Ndiaye', 'Njaay'));
        $this->assertGreaterThanOrEqual(0.9, $this->names->similarity('Ousmane', 'Ousmanne')); // faute de frappe
        $this->assertLessThan(0.8, $this->names->similarity('Diallo', 'Diop'));
        $this->assertSame(0.0, $this->names->similarity('', 'Diop'));
    }

    public function test_jaro_winkler_reference_values(): void
    {
        $this->assertEqualsWithDelta(0.961, JaroWinkler::similarity('martha', 'marhta'), 0.001);
        $this->assertEqualsWithDelta(0.840, JaroWinkler::similarity('dwayne', 'duane'), 0.001);
        $this->assertSame(1.0, JaroWinkler::similarity('abc', 'abc'));
        $this->assertSame(0.0, JaroWinkler::similarity('abc', ''));
    }

    public function test_misspelled_and_partial_names_phase_8(): void
    {
        // Orthographe wolof / française / arabe
        $this->assertGreaterThanOrEqual(0.95, $this->names->similarity('Thiaw', 'Caw'));
        $this->assertGreaterThanOrEqual(0.95, $this->names->similarity('Khady', 'Xadi'));
        $this->assertGreaterThanOrEqual(0.95, $this->names->similarity('Diédhiou', 'Jeju'));
        $this->assertGreaterThanOrEqual(0.95, $this->names->similarity('Ousseynou', 'Housseynou'));
        // Lecture OCR : mots collés, chiffres à la place de lettres
        $this->assertGreaterThanOrEqual(0.95, $this->names->similarity('MOUSSAIBRAHIMA', 'Moussa Ibrahima'));
        $this->assertSame(1.0, $this->names->similarity('NDIAY3', 'Ndiaye'));
        // Prénom composé incomplet
        $this->assertGreaterThanOrEqual(0.88, $this->names->similarity('Mame Diarra', 'Diarra'));
        $this->assertGreaterThanOrEqual(0.88, $this->names->similarity('Mamadou Lamine', 'Lamine'));
        // … mais pas de rapprochement abusif
        $this->assertLessThan(0.8, $this->names->similarity('Awa', 'Astou'));
        $this->assertLessThan(0.8, $this->names->similarity('Sow', 'Sall'));
        $this->assertLessThan(0.85, $this->names->similarity('Fatou Diop', 'Moussa'));
    }
}
