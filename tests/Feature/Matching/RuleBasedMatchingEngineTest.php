<?php

namespace Tests\Feature\Matching;

use App\Models\DeclarationDePerte;
use App\Models\DeclarationItem;
use App\Models\Document;
use App\Services\Matching\MatchingEngine;
use App\Support\SensitiveData;
use Tests\TestCase;

/**
 * Le moteur est testé sur des objets en mémoire : pas de base, que des règles.
 */
class RuleBasedMatchingEngineTest extends TestCase
{
    private MatchingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(MatchingEngine::class);
    }

    private function lost(string $first, string $last, array $extra = []): DeclarationItem
    {
        $item = new DeclarationItem(['first_name' => $first, 'last_name' => $last, 'birth_date_in_doc' => $extra['birth'] ?? null]);
        $item->doc_number_hash = isset($extra['number']) ? SensitiveData::hash(SensitiveData::normalizeDocNumber($extra['number'])) : null;
        $item->setRelation('declaration', new DeclarationDePerte(['lost_at' => $extra['lost_at'] ?? null]));

        return $item;
    }

    private function found(string $first, string $last, array $extra = []): Document
    {
        $doc = new Document([
            'OwnerFirstName'    => $first,
            'OwnerLastName'     => $last,
            'birth_date_in_doc' => $extra['birth'] ?? null,
            'found_at'          => $extra['found_at'] ?? null,
        ]);
        $doc->doc_number_hash = isset($extra['number']) ? SensitiveData::hash(SensitiveData::normalizeDocNumber($extra['number'])) : null;

        return $doc;
    }

    public function test_identical_names_alone_go_to_agent_review(): void
    {
        $r = $this->engine->score($this->lost('Awa', 'Ndiaye'), $this->found('Awa', 'Ndiaye'));

        // 70 (noms) + moitiés des critères inconnus (7.5 + 5 + 2.5) = 85
        $this->assertSame(85, $r->score);
        $this->assertFalse($r->numberMatch);
        $this->assertSame(1.0, $r->nameScore);
        $this->assertArrayHasKey('names', $r->details);
    }

    public function test_senegalese_spelling_variants_still_score_high(): void
    {
        $r = $this->engine->score($this->lost('Mouhamadou', 'Ndiaye'), $this->found('Mohamed', 'Njaay'));

        $this->assertGreaterThanOrEqual(80, $r->score);
    }

    public function test_swapped_first_and_last_names_are_detected(): void
    {
        $r = $this->engine->score($this->lost('Fatou', 'Sarr'), $this->found('Sarr', 'Fatou'));

        $this->assertGreaterThanOrEqual(80, $r->score);
        $this->assertTrue($r->details['names']['swapped']);
    }

    public function test_typo_is_tolerated(): void
    {
        $r = $this->engine->score($this->lost('Ousmane', 'Diallo'), $this->found('Ousmanne', 'Dialo'));

        $this->assertGreaterThanOrEqual(75, $r->score);
    }

    public function test_sibling_with_same_last_name_scores_low(): void
    {
        $r = $this->engine->score($this->lost('Awa', 'Ndiaye'), $this->found('Moussa', 'Ndiaye'));

        $this->assertLessThan(55, $r->score);
    }

    public function test_homonym_with_different_birth_date_is_capped(): void
    {
        $r = $this->engine->score(
            $this->lost('Awa', 'Ndiaye', ['birth' => '1990-05-12']),
            $this->found('Awa', 'Ndiaye', ['birth' => '1985-01-01']),
        );

        $this->assertLessThanOrEqual(45, $r->score);
        $this->assertSame('différente', $r->details['birth_date']['result']);
    }

    public function test_same_birth_date_raises_the_score(): void
    {
        $r = $this->engine->score(
            $this->lost('Awa', 'Ndiaye', ['birth' => '1990-05-12']),
            $this->found('Awa', 'Ndiaye', ['birth' => '1990-05-12']),
        );

        $this->assertSame(93, $r->score); // 70 + 15 + 5 + 2.5
    }

    public function test_same_document_number_is_decisive(): void
    {
        $r = $this->engine->score(
            $this->lost('Awa', 'Ndiaye', ['number' => '1 234 5678 90123']),
            $this->found('Awa', 'Ndiay', ['number' => '1234567890123']),
        );

        $this->assertTrue($r->numberMatch);
        $this->assertGreaterThanOrEqual(95, $r->score);
    }

    public function test_different_document_numbers_cap_the_score(): void
    {
        $r = $this->engine->score(
            $this->lost('Awa', 'Ndiaye', ['number' => '1234567890123']),
            $this->found('Awa', 'Ndiaye', ['number' => '9999999999999']),
        );

        $this->assertFalse($r->numberMatch);
        $this->assertLessThanOrEqual(30, $r->score);
    }

    public function test_document_found_long_before_the_loss_loses_temporal_points(): void
    {
        $before = $this->engine->score(
            $this->lost('Awa', 'Ndiaye', ['lost_at' => '2026-06-01']),
            $this->found('Awa', 'Ndiaye', ['found_at' => '2026-01-10']),
        );
        $after = $this->engine->score(
            $this->lost('Awa', 'Ndiaye', ['lost_at' => '2026-06-01']),
            $this->found('Awa', 'Ndiaye', ['found_at' => '2026-06-03']),
        );

        $this->assertSame(0.0, (float) $before->details['temporal']['points']);
        $this->assertSame(10.0, (float) $after->details['temporal']['points']);
        $this->assertGreaterThan($before->score, $after->score);
    }
}
