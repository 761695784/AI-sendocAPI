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
        $type   = $this->documentType($texts, $mrz);
        $visual = $this->visualFields($lines, $type['name'] ?? null);
        $fields = $this->merge($mrz, $visual);

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

    private function visualFields(array $lines, ?string $typeName = null): array
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
            $labelText = self::withoutFieldNumber($line['text']);
            $compact   = self::compact($labelText);
            foreach ($labels as $label) {
                if ($label['key'] !== '' && self::isLabel($labelText, $compact, $label['key'])) {
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
            // Date écrite sur la même ligne que son libellé (« 4b. Date d'expiration 12/05/2030 »).
            if ($value === '' && in_array($field, ['birth_date', 'expiry_date'], true) && self::parseDate($text) !== null) {
                $value = $text;
            }
            if ($value === '' || self::compact($value) === '') {
                // Valeur en dessous (carte, passeport), sinon à droite sur la même ligne (« 5. N° Permis   12345 »).
                $j = null;
                foreach ([$this->valueBelow($lines, $i, $labelLines), $this->valueRight($lines, $i, $labelLines)] as $candidate) {
                    if ($candidate !== null && $this->normalize($field, $lines[$candidate]['text']) !== null) {
                        $j = $candidate;
                        break;
                    }
                }
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

        // Carte grise : « Titulaire » = « M. NOM PRÉNOMS » sur une ligne.
        if (isset($found['holder']) && ! isset($found['last_name']) && ! isset($found['first_name'])) {
            [$last, $first] = self::splitHolder($found['holder']['value']);
            if ($last !== null && $first !== null) {
                $found['last_name']  = ['value' => self::titleCase($last), 'confidence' => $found['holder']['confidence'], 'source' => 'label'];
                $found['first_name'] = ['value' => self::titleCase($first), 'confidence' => $found['holder']['confidence'], 'source' => 'label'];
            }
        }
        unset($found['holder']);

        return self::compact((string) $typeName) === self::compact('Passeport')
            ? $this->passportNamesByLayout($lines, $labelLines, $found)
            : $this->namesByLayout($lines, $labelLines, $found);
    }

    /**
     * Passeport sénégalais (page de données) : Nom, puis Prénoms, puis Nationalité (« SENEGALAISE »),
     * valeurs en capitales alignées à gauche. Sert quand la zone codée (MRZ) n'est pas sur la photo.
     */
    private function passportNamesByLayout(array $lines, array $labelLines, array $found): array
    {
        if (isset($found['first_name'], $found['last_name'])) {
            return $found;
        }
        $pick = function (int $from, int $direction) use ($lines, $labelLines, $found): ?int {
            [$x, $y, $h] = self::geometry($lines[$from]['box']);
            $best = null;
            $bestDy = PHP_FLOAT_MAX;
            foreach ($lines as $j => $line) {
                if ($j === $from || isset($labelLines[$j]) || in_array($j, [$found['first_name_line'] ?? null, $found['last_name_line'] ?? null], true)) {
                    continue;
                }
                [$x2, $y2] = self::geometry($line['box']);
                $dy = ($y2 - $y) * $direction;
                $text = trim($line['text']);
                if ($dy > $h * 0.6 && $dy < $h * 4.5 && abs($x2 - $x) < $h * 2.5 && $dy < $bestDy
                    && $text === mb_strtoupper($text) && $this->normalize('last_name', $text) !== null) {
                    $best = $j;
                    $bestDy = $dy;
                }
            }

            return $best;
        };
        $set = function (string $field, ?int $j) use ($lines, &$found): ?int {
            if ($j !== null) {
                $found[$field] = ['value' => $this->normalize($field, $lines[$j]['text']), 'confidence' => round($lines[$j]['confidence'] * 0.8, 2), 'source' => 'layout'];
                $found[$field . '_line'] = $j;
            }

            return $j;
        };

        if (isset($found['first_name']) && is_int($found['first_name_line'] ?? null)) {
            $set('last_name', $pick($found['first_name_line'], -1));
        } elseif (isset($found['last_name']) && is_int($found['last_name_line'] ?? null)) {
            $set('first_name', $pick($found['last_name_line'], 1));
        } else {
            foreach ($lines as $i => $line) {
                if (in_array(self::compact($line['text']), config('ocr.nationality_values', []), true)) {
                    if (($j = $set('first_name', $pick($i, -1))) !== null) {
                        $set('last_name', $pick($j, -1));
                    }
                    break;
                }
            }
        }

        return $found;
    }

    /** « M. DIOUM BASSIROU » → ['DIOUM', 'BASSIROU'] ; sans espace lisible (« MITOUREMAMEMOR ») : rien. */
    private static function splitHolder(string $value): array
    {
        $v = trim(preg_replace('/^(MONSIEUR|MADAME|MLLE|MME|MR|M)\s*[.\s]\s*/u', '', mb_strtoupper(trim($value))));
        $parts = preg_split('/\s+/u', $v);

        return count($parts) >= 2 && mb_strlen($parts[0]) >= 2 ? [$parts[0], implode(' ', array_slice($parts, 1))] : [null, null];
    }

    /** Retire la numérotation des champs du permis : « 1. Nom », « 4b. Date… », « 5N° Permis ». */
    private static function withoutFieldNumber(string $text): string
    {
        return preg_replace('/^\s*\d{1,2}\s*[a-cA-C]?\s*[.)]?\s*(?=\D)/u', '', $text) ?? $text;
    }

    /**
     * Carte CEDEAO : les petits libellés (« Prénoms », « Nom ») sont souvent illisibles sur une photo,
     * alors que les valeurs en capitales sont bien lues. La disposition est fixe :
     * numéro de la carte, puis prénoms, puis nom, alignés à gauche. On s'en sert pour compléter.
     */
    private function namesByLayout(array $lines, array $labelLines, array $found): array
    {
        if (isset($found['first_name'], $found['last_name'])) {
            return $found;
        }

        $used = array_flip(array_filter([$found['first_name_line'] ?? null, $found['last_name_line'] ?? null, $found['doc_number_line'] ?? null], 'is_int'));
        // Ligne « nom » la plus proche au-dessus (-1) ou au-dessous (+1) de la ligne $from, alignée à gauche.
        $neighbour = function (int $from, int $direction, float $maxGap) use ($lines, $labelLines, &$used): ?int {
            [$x, $y, $h] = self::geometry($lines[$from]['box']);
            $best = null;
            $bestDy = PHP_FLOAT_MAX;
            foreach ($lines as $j => $line) {
                if ($j === $from || isset($labelLines[$j]) || isset($used[$j])) {
                    continue;
                }
                [$x2, $y2] = self::geometry($line['box']);
                $dy = ($y2 - $y) * $direction;
                $text = trim($line['text']);
                if ($dy > $h * 0.6 && $dy < $h * $maxGap && abs($x2 - $x) < $h * 2.5 && $dy < $bestDy
                    && $text === mb_strtoupper($text) && $this->normalize('last_name', $text) !== null) {
                    $best = $j;
                    $bestDy = $dy;
                }
            }

            return $best;
        };
        $take = function (string $field, ?int $j) use ($lines, &$found, &$used): ?int {
            if ($j === null) {
                return null;
            }
            $found[$field] = ['value' => $this->normalize($field, $lines[$j]['text']), 'confidence' => round($lines[$j]['confidence'] * 0.8, 2), 'source' => 'layout'];
            $found[$field . '_line'] = $j;
            $used[$j] = true;

            return $j;
        };

        $first = $found['first_name_line'] ?? null;
        $last  = $found['last_name_line'] ?? null;
        $number = $found['doc_number_line'] ?? null;
        $isCard = is_int($number) && strlen(preg_replace('/\D/', '', $lines[$number]['text'])) === 17;

        if (isset($found['last_name']) && is_int($last)) {
            $take('first_name', $neighbour($last, -1, 4.5));
        } elseif (isset($found['first_name']) && is_int($first)) {
            $take('last_name', $neighbour($first, 1, 4.5));
        } elseif ($isCard) {
            $j = $take('first_name', $neighbour($number, 1, 5.0));
            if ($j !== null) {
                $take('last_name', $neighbour($j, 1, 4.5));
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

    /** Valeur à droite du libellé, sur la même ligne (permis : « 5. N° Permis   12345678 »). */
    private function valueRight(array $lines, int $i, array $labelLines): ?int
    {
        [$x, $y, $h] = self::geometry($lines[$i]['box']);
        $xEnd = max(array_column($lines[$i]['box'], 0));
        $best = null;
        $bestDx = PHP_FLOAT_MAX;
        foreach ($lines as $j => $line) {
            if ($j === $i || isset($labelLines[$j])) {
                continue;
            }
            // Texte en biais (filigrane « SPECIMEN », hologramme) : jamais une valeur.
            [[$ax, $ay], [$bx, $by]] = [$line['box'][0], $line['box'][1]];
            if (abs($by - $ay) > 0.35 * max(1.0, abs($bx - $ax))) {
                continue;
            }
            [$x2, $y2] = self::geometry($line['box']);
            $dx = $x2 - $xEnd;
            if (abs($y2 - $y) < $h * 0.6 && $dx > -$h * 0.5 && $dx < $h * 15 && $dx < $bestDx) {
                $best = $j;
                $bestDx = $dx;
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
                // Plaque d'immatriculation (carte grise) : « AA-263-ND », « DK-1234-AB ».
                if ($field === 'doc_number' && preg_match('/^[A-Z]{1,3}\d{2,4}[A-Z]{1,3}$/', $v)) {
                    return $v;
                }

                return strlen($v) >= 6 && preg_match('/\d{4,}/', $v) ? $v : null;

            case 'holder':
                $clean = trim(preg_replace('/[^\p{L}\s.\'\-]/u', '', $value));

                return mb_strlen($clean) >= 4 && ! preg_match('/\d/', $value) ? $clean : null;
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

        if (! isset($fields['first_name']) || ! isset($fields['last_name'])) {
            $add('names_not_read', 'info', 'Nom ou prénom non lu automatiquement : saisissez-le. Les numéros sont déjà masqués sur la photo.');
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
            // Long libellé mal lu (« Date denainsance ») : jusqu'à 1 erreur pour 7 lettres.
            return strlen($key) >= 12 && strlen($compact) <= strlen($key) + 28
                && levenshtein(substr($compact, 0, strlen($key)), $key) <= intdiv(strlen($key), 7);
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
        } elseif (preg_match('/(\d{1,2})\s*([A-Z]{3,5})\s*\/\s*[A-Z]{3,5}\s*(\d{2,4})/', $t, $m) && isset(self::MONTHS[$m[2]])) {
            [$d, $mo, $y] = [(int) $m[1], self::MONTHS[$m[2]], (int) $m[3]];
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
