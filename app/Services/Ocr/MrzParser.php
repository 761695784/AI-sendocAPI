<?php

namespace App\Services\Ocr;

use Illuminate\Support\Carbon;

/**
 * Zone de lecture automatique (MRZ, norme OACI 9303) : les lignes
 * « IDSEN…<<< » au dos de la carte CEDEAO ou en bas du passeport.
 *
 * Chaque champ important est protégé par un chiffre de contrôle : une lecture
 * validée est donc quasi certaine, et une MRZ qui ne « tombe pas juste » est
 * soit mal lue, soit falsifiée.
 *
 *  - TD1 (cartes) : 3 lignes de 30 caractères.
 *  - TD3 (passeports) : 2 lignes de 44 caractères.
 */
class MrzParser
{
    private const WEIGHTS = [7, 3, 1];

    /**
     * @param  string[]  $lines  toutes les lignes lues sur la photo (ordre de lecture)
     * @return array|null  null si aucune MRZ reconnue
     */
    public function parse(array $lines): ?array
    {
        $candidates = [];
        foreach (array_values($lines) as $i => $text) {
            $clean = self::cleanLine($text);
            if ($clean !== null) {
                $candidates[] = ['index' => $i, 'text' => $clean];
            }
        }

        // Passeport : 2 lignes consécutives d'environ 44 caractères, la 1re commence par P.
        for ($k = 0; $k + 1 < count($candidates); $k++) {
            $a = $candidates[$k]['text'];
            $b = $candidates[$k + 1]['text'];
            if ($a[0] === 'P' && self::near(strlen($a), 44) && self::near(strlen($b), 44)) {
                return $this->td3(self::fit($a, 44), self::fit($b, 44), [$candidates[$k]['index'], $candidates[$k + 1]['index']]);
            }
        }

        // Carte : 3 lignes consécutives d'environ 30 caractères.
        for ($k = 0; $k + 2 < count($candidates); $k++) {
            [$a, $b, $c] = [$candidates[$k]['text'], $candidates[$k + 1]['text'], $candidates[$k + 2]['text']];
            if (self::near(strlen($a), 30) && self::near(strlen($b), 30) && self::near(strlen($c), 30) && in_array($a[0], ['I', 'A', 'C'], true)) {
                return $this->td1(self::fit($a, 30), self::fit($b, 30), self::fit($c, 30), array_column(array_slice($candidates, $k, 3), 'index'));
            }
        }

        return null;
    }

    public static function checkDigit(string $data): int
    {
        $sum = 0;
        foreach (str_split($data) as $i => $char) {
            $sum += self::value($char) * self::WEIGHTS[$i % 3];
        }

        return $sum % 10;
    }

    public static function checks(string $data, string $digit): bool
    {
        return ctype_digit($digit) && self::checkDigit($data) === (int) $digit;
    }

    // ── Formats ──────────────────────────────────────────────────────

    private function td1(string $l1, string $l2, string $l3, array $indexes): array
    {
        // Numéro + zone facultative : corrige les confusions lettres/chiffres (O→0…).
        $l1       = substr($l1, 0, 5) . self::digitsFix(substr($l1, 5), letters: true);
        $code     = rtrim(substr($l1, 0, 2), '<');
        $optional = substr($l1, 15, 15);

        // Numéro long (> 9 caractères, ex. carte CEDEAO à 17 chiffres) : règle OACI.
        $numberField = self::digitsFix(substr($l1, 5, 9), letters: true);
        if (substr($l1, 14, 1) === '<' && preg_match('/^([0-9A-Z]+?)(\d)</', $optional, $m)) {
            $number      = $numberField . self::digitsFix($m[1], letters: true);
            $numberCheck = self::checks($number, $m[2]);
        } else {
            $number      = rtrim($numberField, '<');
            $numberCheck = self::checks($numberField, substr($l1, 14, 1));
        }

        [$birth, $birthCheck, $birthOk]    = $this->dateField(substr($l2, 0, 6), substr($l2, 6, 1), true);
        [$expiry, $expiryCheck, $expiryOk] = $this->dateField(substr($l2, 8, 6), substr($l2, 14, 1), false);

        $composite = self::checks(
            substr($l1, 5, 25) . substr($birthCheck, 0, 7) . substr($expiryCheck, 0, 7) . substr($l2, 18, 11),
            substr($l2, 29, 1)
        );

        [$surname, $given] = self::names($l3);

        return $this->result('TD1', $code, substr($l1, 2, 3), $number, $birth, substr($l2, 7, 1), $expiry,
            substr($l2, 15, 3), $surname, $given,
            ['document_number' => $numberCheck, 'birth_date' => $birthOk, 'expiry_date' => $expiryOk, 'composite' => $composite],
            $indexes, rtrim($optional, '<'));
    }

    private function td3(string $l1, string $l2, array $indexes): array
    {
        $numberField = self::digitsFix(substr($l2, 0, 9), letters: true);
        $numberOk    = self::checks($numberField, substr($l2, 9, 1));

        [$birth, $birthCheck, $birthOk]    = $this->dateField(substr($l2, 13, 6), substr($l2, 19, 1), true);
        [$expiry, $expiryCheck, $expiryOk] = $this->dateField(substr($l2, 21, 6), substr($l2, 27, 1), false);

        $personal   = substr($l2, 28, 14);
        $personalOk = str_replace('<', '', $personal) === '' ? true : self::checks($personal, substr($l2, 42, 1));
        $composite  = self::checks(
            $numberField . substr($l2, 9, 1) . $birthCheck . $expiryCheck . $personal . substr($l2, 42, 1),
            substr($l2, 43, 1)
        );

        [$surname, $given] = self::names(substr($l1, 5));

        return $this->result('TD3', rtrim(substr($l1, 0, 2), '<'), substr($l1, 2, 3), rtrim($numberField, '<'), $birth,
            substr($l2, 20, 1), $expiry, substr($l2, 10, 3), $surname, $given,
            ['document_number' => $numberOk, 'birth_date' => $birthOk, 'expiry_date' => $expiryOk, 'personal_number' => $personalOk, 'composite' => $composite],
            $indexes, rtrim($personal, '<'));
    }

    private function result(string $format, string $code, string $state, string $number, ?string $birth, string $sex,
        ?string $expiry, string $nationality, string $surname, string $given, array $checks, array $indexes, string $optional): array
    {
        return [
            'format'          => $format,
            'document_code'   => $code,
            'issuing_state'   => str_replace('<', '', $state),
            'document_number' => $number,
            'birth_date'      => $birth,
            'sex'             => in_array($sex, ['M', 'F'], true) ? $sex : null,
            'expiry_date'     => $expiry,
            'nationality'     => str_replace('<', '', $nationality),
            'surname'         => $surname,
            'given_names'     => $given,
            'optional_data'   => $optional,
            'checks'          => $checks,
            'valid'           => ! in_array(false, $checks, true),
            'line_indexes'    => array_values($indexes),
        ];
    }

    // ── Outils ───────────────────────────────────────────────────────

    /**
     * Nettoie une ligne lue : majuscules, sans espaces, « « » → « < ».
     * Renvoie null si ce n'est manifestement pas une ligne MRZ.
     */
    public static function cleanLine(string $text): ?string
    {
        $t = strtoupper(str_replace([' ', "\t", '«', '‹', '≤', '＜'], ['', '', '<', '<', '<', '<'], $text));
        $t = preg_replace('/[^A-Z0-9<]/', '', $t);
        // Suites de chevrons lues « K » (fréquent) : KKK / K<K → <<<
        $t = preg_replace_callback('/[<K]{3,}$/', fn ($m) => str_repeat('<', strlen($m[0])), $t);
        $t = preg_replace_callback('/<[K<]*<$/', fn ($m) => str_repeat('<', strlen($m[0])), $t);

        if (strlen($t) < 26 || strlen($t) > 48 || substr_count($t, '<') < 2) {
            return null;
        }

        return $t;
    }

    private static function near(int $length, int $target): bool
    {
        return abs($length - $target) <= 2;
    }

    /** Longueur exacte : complète par « < » ou coupe les caractères en trop à la fin. */
    private static function fit(string $line, int $length): string
    {
        return strlen($line) >= $length ? substr($line, 0, $length) : str_pad($line, $length, '<');
    }

    /** Date AAMMJJ + contrôle, avec correction des confusions O/0, I/1… */
    private function dateField(string $raw, string $check, bool $isBirth): array
    {
        $digits = self::digitsFix($raw);
        $check  = self::digitsFix($check);
        $ok     = self::checks($digits, $check);

        $date = null;
        if (preg_match('/^\d{6}$/', $digits)) {
            $yy = (int) substr($digits, 0, 2);
            $century = $isBirth ? ($yy > (int) now()->format('y') ? 1900 : 2000) : 2000;
            try {
                $date = Carbon::createFromFormat('!Y-m-d', sprintf('%04d-%s-%s', $century + $yy, substr($digits, 2, 2), substr($digits, 4, 2)));
                $date = $date && $date->format('ymd') === $digits ? $date->toDateString() : null;
            } catch (\Throwable) {
                $date = null;
            }
        }

        return [$date, $digits . $check, $ok];
    }

    /** Champs numériques : O→0, I/L→1, Z→2, S→5, G→6, B→8 (confusions classiques de l'OCR). */
    public static function digitsFix(string $s, bool $letters = false): string
    {
        if ($letters) {
            // Numéro de document alphanumérique (passeport « A01234567 ») : on ne corrige que la partie finale chiffrée.
            if (preg_match('/^([A-Z]{0,2})(.*)$/', $s, $m) && $m[1] !== '' && preg_match('/\d/', $m[2])) {
                return $m[1] . strtr($m[2], 'OILZSGBQD', '011256800');
            }
            if (preg_match('/\d{3,}/', $s)) {
                return strtr($s, 'OILZSGBQD', '011256800');
            }

            return $s;
        }

        return strtr($s, 'OILZSGBQD', '011256800');
    }

    /** « NDIAYE<<AWA<MARIE » → ['NDIAYE', 'AWA MARIE'] (chiffres lus à la place de lettres corrigés). */
    private static function names(string $field): array
    {
        $field = strtr($field, '0158264', 'OISBZGA');
        $parts = explode('<<', trim($field, '<'), 2);
        $clean = fn ($s) => trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', $s)));

        return [$clean($parts[0] ?? ''), $clean($parts[1] ?? '')];
    }

    private static function value(string $char): int
    {
        if (ctype_digit($char)) {
            return (int) $char;
        }
        if ($char >= 'A' && $char <= 'Z') {
            return ord($char) - 55;
        }

        return 0;
    }
}
