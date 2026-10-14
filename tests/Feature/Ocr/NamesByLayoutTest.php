<?php

namespace Tests\Feature\Ocr;

use App\Services\Ocr\DocumentReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Photo réelle de carte CEDEAO : petits libellés illisibles, valeurs en capitales bien lues. */
class NamesByLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function line(string $text, int $x, int $y, int $h = 36): array
    {
        $w = strlen($text) * 20;

        return ['text' => $text, 'confidence' => 0.95, 'box' => [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]]];
    }

    private function fields(array $lines): array
    {
        return app(DocumentReader::class)->read(['lines' => $lines])['fields'];
    }

    private function card(array $labels): array
    {
        return array_merge([
            $this->line('CARTE D\'IDENTITE CEDEAO', 330, 336),
            $this->line('1 07 19990101 00012 3', 436, 426),
            $this->line('AWA', 435, 489),
            $this->line('NDIAYE', 437, 554),
            $this->line('DUPLICATA', 90, 585),
            $this->line('01/01/1999', 441, 620),
            $this->line('MBOUR', 442, 680),
        ], $labels);
    }

    public function test_no_label_read(): void
    {
        $f = $this->fields($this->card([]));
        $this->assertSame('Awa', $f['first_name']['value']);
        $this->assertSame('Ndiaye', $f['last_name']['value']);
        $this->assertSame('layout', $f['first_name']['source']);
    }

    public function test_only_surname_label_read(): void
    {
        $f = $this->fields($this->card([$this->line('Nom', 436, 532, 18)]));
        $this->assertSame('Ndiaye', $f['last_name']['value']);
        $this->assertSame('Awa', $f['first_name']['value']);
    }

    public function test_only_first_name_label_read(): void
    {
        $f = $this->fields($this->card([$this->line('Prénoms', 436, 468, 18)]));
        $this->assertSame('Awa', $f['first_name']['value']);
        $this->assertSame('Ndiaye', $f['last_name']['value']);
    }
}
