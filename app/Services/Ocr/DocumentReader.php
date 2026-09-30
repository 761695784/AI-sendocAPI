<?php

namespace App\Services\Ocr;

use App\Models\DocumentType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Interprète le texte lu par l'OCR sur une pièce : type, nom, prénoms,
 * numéro(s), dates, sexe — puis contrôle la cohérence (MRZ, structure du
 * numéro CEDEAO, concordance des champs).
 *
 * Tout est déterministe et testé : l'OCR lit, DocTrack comprend.
 * Le résultat est une PROPOSITION : la personne vérifie avant de publier.
 */
class DocumentReader
{
    private const FIELD_ORDER = ['first_name', 'last_name', 'doc_number', 'nin', 'birth_date', 'sex', 'expiry_date'];

    private const MONTHS = [
        'JAN' => 1, 'JANV' => 1, 'FEB' => 2, 'FEV' => 2, 'FEVR' => 2, 'MAR' => 3, 'MARS' => 3, 'APR' => 4, 'AVR' => 4, 'AVRIL' => 4,
        'MAY' => 5, 'MAI' => 5, 'JUN' => 6, 'JUIN' => 6, 'JUL' => 7, 'JUIL' => 7, 'AUG' => 8, 'AOU' => 8, 'AOUT' => 8,
        'SEP' => 9, 'SEPT' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    public function __construct(private MrzParser $mrzParser) {}

    /**
     * @param  array{lines: array<int, array{text: string, confidence: float, box: array}>}  $ocr
     */
    public function read(array $ocr): array
    {
        $min   = (float) config('ocr.min_line_confidence', 0.5);
        $lines = array_values(array_filter($ocr['lines'] ?? [], fn ($l) => ($l['confidence'] ?? 0) >= $min && trim($l['text'] ?? '') !== ''));
        $texts = array_column($lines, 'text');

        $mrz    = $this->mrzParser->parse($texts);
        $visual = $this->visualFields($lines);
        $fields = $this->merge($mrz, $visual);
        $type   = $this->documentType($texts, $mrz);

        $checks = $this->checks($fields, $visual, $mrz);
        $numbers = $this->numbers($fields, $visual, $mrz, $type);

        return [
            'document_type'     => $type,
            'fields'            => $fields,
            'numbers'           => $numbers,
            'mrz'               => $mrz ? [
                'present' => true,
                'format'  => $mrz['format'],
                'valid'   => $mrz['valid'],
                'checks'  => $mrz['checks'],
            ] : ['present' => false],
            'checks'            => $checks,
            'quality'           => $this->quality($lines, $fields, $type, $mrz, $checks),
            'sensitive_lines'   => $this->sensitiveLines($lines, $numbers, $mrz),
            'lines_count'       => count($lines),
        ];
    }

    // ── Face visible : libellés puis valeurs ─────────────────────────

    private function visualFields(array $lines): array
    {
        $labels = [];
        foreach (config('ocr.labels', []) as $field => $words) {
            foreach ($words as $w) {
                $labels[] = ['field' => $field, 'key' => self::compact($w)];
            }
        }
        usort($labels, fn ($a, $b) => strlen($b['key']) <=> strlen($a['key']));

        $found = [];
        $labelLines = [];
        foreach ($lines as $i => $line) {
            $compact = self::compact($line['text']);
            foreach ($labels as $label) {
                if ($label['key'] !== '' && self::isLabel($line['text'], $compact, $label['key'])) {
                    $labelLines[$i] = $label['field'];
                    break;
                }
            }
        }

        foreach ($labelLines as $i => $field) {
            if (isset($found[$field])) {
                continue;
            }
            $text = $lines[$i]['text'];
            // « Nom : NDIAYE » sur la même ligne.
            $value = str_contains($text, ':') ? trim(Str::after($text, ':')) : '';
            $conf  = $lines[$i]['confidence'];
            if ($value === '' || self::compact($value) === '') {
                $j = $this->valueBelow($lines, $i, $labelLines);
                if ($j === null) {
                    continue;
                }
                $value = $lines[$j]['text'];
                $conf  = $lines[$j]['confidence'];
                $found[$field . '_line'] = $j;
            } else {
                $found[$field . '_line'] = $i;
            }
            if (($normalized = $this->normalize($field, $value)) !== null) {
                $found[$field] = ['value' => $normalized, 'confidence' => round($conf, 2), 'source' => 'label'];
            }
        }

        // Numéros sans libellé reconnu : motifs.
        foreach ($lines as $i => $line) {
            $digits = preg_replace('/\D/', '', $line['text']);
            $compact = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $line['text']));
            if (! isset($found['doc_number']) && strlen($digits) === 17 && strlen($compact) === 17) {
                $found['doc_number'] = ['value' => $digits, 'confidence' => round($line['confidence'], 2), 'source' => 'pattern'];
                $found['doc_number_line'] = $i;
            } elseif (! isset($found['doc_number']) && preg_match('/^[A-Z]{1,2}\d{7,8}$/', $compact)) {
                $found['doc_number'] = ['value' => $compact, 'confidence' => round($line['confidence'], 2), 'source' => 'pattern'];
                $found['doc_number_line'] = $i;
            }
        }

        return $found;
    }

    /** Ligne de valeur sous un libellé : juste en dessous, alignée à gauche, pas elle-même un libellé. */
    private function valueBelow(array $lines, int $i, array $labelLines): ?int
    {
        [$x, $y, $h] = self::geometry($lines[$i]['box']);
        $best = null;
        $bestDy = PHP_FLOAT_MAX;
        foreach ($lines as $j => $line) {
            if ($j === $i || isset($labelLines[$j])) {
                continue;
            }
            [$x2, $y2] = self::geometry($line['box']);
            $dy = $y2 - $y;
            if ($dy > $h * 0.4 && $dy < $h * 3.2 && abs($x2 - $x) < $h * 3 && $dy < $bestDy) {
                $best = $j;
                $bestDy = $dy;
            }
        }

        return $best;
    }

    private function normalize(string $field, string $value): ?string
    {
        $value = trim($value);

        switch ($field) {
            case 'first_name':
            case 'last_name':
                $clean = trim(preg_replace('/[^\p{L}\s\'\-]/u', '', $value));
                if ($clean === '' || preg_match('/\d/', $value) || mb_strlen($clean) < 2) {
                    return null;
                }
                foreach (config('ocr.noise', []) as $noise) {
                    if (self::compact($clean) === self::compact($noise)) {
                        return null;
                    }
                }

                return self::titleCase($clean);

            case 'birth_date':
            case 'expiry_date':
                return self::parseDate($value);

            case 'sex':
                $v = strtoupper(Str::ascii($value));

                return match (true) {
                    str_starts_with($v, 'M') || str_starts_with($v, 'H') => 'M',
                    str_starts_with($v, 'F') => 'F',
                    default => null,
                };

            case 'doc_number':
            case 'nin':
                $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));

                return strlen($v) >= 6 && preg_match('/\d{4,}/', $v) ? $v : null;
        }

        return null;
    }

    // ── Fusion MRZ + face visible ────────────────────────────────────

    private function merge(?array $mrz, array $visual): array
    {
        $fields = [];
        $fromMrz = [];
        if ($mrz) {
            $conf = $mrz['valid'] ? 0.99 : 0.6;
            $fromMrz = array_filter([
                'last_name'   => $mrz['surname'] !== '' ? self::titleCase($mrz['surname']) : null,
                'first_name'  => $mrz['given_names'] !== '' ? self::titleCase($mrz['given_names']) : null,
                'doc_number'  => ($mrz['checks']['document_number'] ?? false) ? $mrz['document_number'] : null,
                'birth_date'  => ($mrz['checks']['birth_date'] ?? false) ? $mrz['birth_date'] : null,
                'expiry_date' => ($mrz['checks']['expiry_date'] ?? false) ? $mrz['expiry_date'] : null,
                'sex'         => $mrz['sex'],
            ]);
            foreach ($fromMrz as $k => $v) {
                $fromMrz[$k] = ['value' => $v, 'confidence' => $conf, 'source' => 'mrz'];
            }
        }

        foreach (self::FIELD_ORDER as $field) {
            $m = $fromMrz[$field] ?? null;
            $v = $visual[$field] ?? null;
            // MRZ validée > face visible > MRZ non validée.
            $fields[$field] = match (true) {
                $m && $m['confidence'] >= 0.99 => $m,
                $v !== null                    => $v,
                default                        => $m,
            };
        }

        return array_filter($fields);
    }

    private function documentType(array $texts, ?array $mrz): ?array
    {
        $types = DocumentType::all();
        $find  = fn (string $name) => $types->first(fn ($t) => self::compact($t->TypeName) === self::compact($name));

        if ($mrz && ($name = config('ocr.mrz_document_types.' . substr($mrz['document_code'], 0, 1))) && ($type = $find($name))) {
            return ['id' => $type->id, 'name' => $type->TypeName, 'confidence' => 0.99, 'source' => 'mrz'];
        }

        $all = self::compact(implode(' ', $texts));
        foreach (config('ocr.document_types', []) as $name => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($all, self::compact($kw)) && ($type = $find($name))) {
                    return ['id' => $type->id, 'name' => $type->TypeName, 'confidence' => 0.85, 'source' => 'keywords'];
                }
            }
        }

        return null;
    }

    // ── Contrôles ────────────────────────────────────────────────────

    private function checks(array $fields, array $visual, ?array $mrz): array
    {
        $checks = [];
        $add = function (string $code, string $level, string $message) use (&$checks) {
            $checks[] = compact('code', 'level', 'message');
        };

        if ($mrz) {
            $mrz['valid']
                ? $add('mrz_valid', 'ok', 'Zone codée (MRZ) lue et chiffres de contrôle corrects.')
                : $add('mrz_invalid', 'warning', 'Zone codée (MRZ) lue mais chiffres de contrôle incorrects : photo à reprendre, ou document à vérifier.');

            foreach (['last_name', 'first_name'] as $f) {
                if (isset($visual[$f]) && $mrz[$f === 'last_name' ? 'surname' : 'given_names'] !== ''
                    && self::compact($visual[$f]['value']) !== self::compact($mrz[$f === 'last_name' ? 'surname' : 'given_names'])) {
                    $add('name_mismatch', 'warning', 'Le nom imprimé ne correspond pas à la zone codée (MRZ).');
                    break;
                }
            }
        }

        $number = $fields['doc_number']['value'] ?? null;
        if ($number && strlen($number) === 17 && ctype_digit($number)) {
            $card = SenegalIdNumber::analyze($number);
            if (! $card['structure_ok']) {
                $add('number_structure', 'warning', 'Le numéro à 17 chiffres ne respecte pas la structure CEDEAO (sexe + région + date de naissance…).');
            } else {
                $add('number_structure_ok', 'ok', 'Numéro de carte CEDEAO cohérent.');
                if (isset($fields['birth_date']) && $card['birth_date'] !== $fields['birth_date']['value']) {
                    $add('birth_date_mismatch', 'warning', 'La date de naissance ne correspond pas à celle contenue dans le numéro de carte.');
                }
                if (isset($fields['sex']) && $card['sex'] !== $fields['sex']['value']) {
                    $add('sex_mismatch', 'warning', 'Le sexe ne correspond pas à celui contenu dans le numéro de carte.');
                }
            }
        }

        if (isset($fields['expiry_date']) && $fields['expiry_date']['value'] < now()->toDateString()) {
            $add('expired', 'info', 'Pièce expirée (elle peut quand même être restituée à son propriétaire).');
        }

        return $checks;
    }

    /** Tous les numéros lus (le principal + le NIN) : servent aux correspondances. */
    private function numbers(array $fields, array $visual, ?array $mrz, ?array $type): array
    {
        $numbers = [];
        if ($n = $fields['doc_number']['value'] ?? null) {
            $kind = match (true) {
                $type && self::compact($type['name']) === self::compact('Passeport') => 'passport_number',
                strlen($n) === 17 && ctype_digit($n)                                  => 'card_number',
                default                                                               => 'document_number',
            };
            $numbers[] = ['kind' => $kind, 'value' => $n];
        }
        if (($nin = $fields['nin']['value'] ?? null) && $nin !== ($fields['doc_number']['value'] ?? null)) {
            $numbers[] = ['kind' => 'nin', 'value' => $nin];
        }

        return $numbers;
    }

    private function quality(array $lines, array $fields, ?array $type, ?array $mrz, array $checks): array
    {
        $issues = [];
        $avg = $lines ? array_sum(array_column($lines, 'confidence')) / count($lines) : 0;

        if (count($lines) < config('ocr.min_lines', 4)) {
            $issues[] = 'unreadable';
        } elseif ($avg < config('ocr.blurry_average', 0.8)) {
            $issues[] = 'blurry';
        }
        if (! $type) {
            $issues[] = 'type_unknown';
        }
        foreach ($checks as $c) {
            if ($c['level'] === 'warning') {
                $issues[] = $c['code'];
            }
        }

        $essentials = count(array_intersect_key($fields, array_flip(['first_name', 'last_name', 'doc_number'])));
        $score = (int) round(min(100, $avg * 50 + $essentials * 12 + ($type ? 6 : 0) + ($mrz && $mrz['valid'] ? 8 : 0)
            - 10 * count(array_filter($checks, fn ($c) => $c['level'] === 'warning'))));

        return [
            'score'              => max(0, $score),
            'average_confidence' => round($avg, 3),
            'issues'             => array_values(array_unique($issues)),
            'status'             => match (true) {
                in_array('unreadable', $issues, true) || $essentials === 0 => 'unreadable',
                $essentials === 3 && $type !== null                        => 'read',
                default                                                    => 'partial',
            },
        ];
    }

    /** Lignes à masquer sur la photo publique : zone MRZ et lignes portant un numéro. */
    private function sensitiveLines(array $lines, array $numbers, ?array $mrz): array
    {
        $indexes = $mrz ? $mrz['line_indexes'] : [];
        $values  = array_filter(array_map(fn ($n) => $n['value'], $numbers));

        foreach ($lines as $i => $line) {
            $compact = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $line['text']));
            if (preg_match('/\d{6,}/', $compact)) {
                $indexes[] = $i;
                continue;
            }
            foreach ($values as $v) {
                if (strlen($compact) >= 6 && (str_contains($v, $compact) || str_contains($compact, $v))) {
                    $indexes[] = $i;
                }
            }
        }
        $indexes = array_values(array_unique($indexes));
        sort($indexes);

        return array_map(fn ($i) => $lines[$i]['box'], $indexes);
    }

    // ── Outils ───────────────────────────────────────────────────────

    /** Libellé en début de ligne ; les libellés courts (« Nom », « NIN ») doivent être seuls ou suivis de « : » ou « / ». */
    private static function isLabel(string $text, string $compact, string $key): bool
    {
        if (! str_starts_with($compact, $key)) {
            return false;
        }
        if (strlen($key) > 4) {
            return strlen($compact) <= strlen($key) + 28;
        }
        $plain = Str::lower(Str::ascii($text));

        return $compact === $key || (bool) preg_match('/^\W*' . preg_quote($key, '/') . '\s*[:\/]/', $plain);
    }

    public static function compact(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($text)));
    }

    public static function titleCase(string $value): string
    {
        return mb_convert_case(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value))), MB_CASE_TITLE, 'UTF-8');
    }

    /** 12/05/1985, 12.05.85, 03 JAN 1990, 14FEB2029, 12 mai 1985 → AAAA-MM-JJ */
    public static function parseDate(string $text): ?string
    {
        $t = strtoupper(Str::ascii(trim($text)));

        if (preg_match('/(\d{1,2})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{2,4})/', $t, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/(\d{1,2})\s*([A-Z]{3,5})\.?\s*(\d{2,4})/', $t, $m) && isset(self::MONTHS[$m[2]])) {
            [$d, $mo, $y] = [(int) $m[1], self::MONTHS[$m[2]], (int) $m[3]];
        } else {
            return null;
        }
        if ($y < 100) {
            $y += $y > (int) now()->format('y') + 15 ? 1900 : 2000;
        }

        return checkdate($mo, $d, $y) && $y > 1900 && $y < 2100 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** @return array{0: float, 1: float, 2: float} x gauche, y haut, hauteur */
    private static function geometry(array $box): array
    {
        $xs = array_column($box, 0);
        $ys = array_column($box, 1);

        return [min($xs), min($ys), max(1.0, max($ys) - min($ys))];
    }
}
