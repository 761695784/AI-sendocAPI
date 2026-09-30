<?php

namespace App\Services\Ocr;

use RuntimeException;

/** Outils image (GD, présent partout, y compris chez Hostinger). */
class ImageTools
{
    /**
     * Copie « à l'endroit » (orientation EXIF des téléphones), réduite à
     * $maxSide pixels, en JPEG. Renvoie [largeur, hauteur].
     */
    public static function normalize(string $source, string $destination, int $maxSide = 2000): array
    {
        $img = self::load($source);

        if (function_exists('exif_read_data') && @exif_imagetype($source) === IMAGETYPE_JPEG) {
            $orientation = (int) (@exif_read_data($source)['Orientation'] ?? 1);
            $img = match ($orientation) {
                3 => imagerotate($img, 180, 0),
                6 => imagerotate($img, -90, 0),
                8 => imagerotate($img, 90, 0),
                default => $img,
            };
        }

        [$w, $h] = [imagesx($img), imagesy($img)];
        $scale = min(1, $maxSide / max($w, $h));
        if ($scale < 1) {
            $resized = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
            imagecopyresampled($resized, $img, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $w, $h);
            $img = $resized;
        }

        self::save($img, $destination);

        return [imagesx($img), imagesy($img)];
    }

    /** Rotation dans le sens horaire (90, 180, 270). Renvoie [largeur, hauteur]. */
    public static function rotate(string $path, int $clockwise): array
    {
        $img = self::load($path);
        if ($clockwise % 360 !== 0) {
            $img = imagerotate($img, -$clockwise, 0); // GD tourne dans le sens inverse des aiguilles
            self::save($img, $path);
        }

        return [imagesx($img), imagesy($img)];
    }

    /**
     * Masque des zones (quadrilatères lus par l'OCR, dans un repère de
     * dimensions $frameW × $frameH) par des bandeaux opaques.
     *
     * @param  array<int, array<int, array{0: float, 1: float}>>  $boxes
     */
    public static function mask(string $source, string $destination, array $boxes, int $frameW, int $frameH, float $padding = 0.35): int
    {
        $img = self::load($source);
        [$w, $h] = [imagesx($img), imagesy($img)];
        $sx = $frameW > 0 ? $w / $frameW : 1;
        $sy = $frameH > 0 ? $h / $frameH : 1;
        $fill = imagecolorallocate($img, 25, 25, 25);
        $text = imagecolorallocate($img, 200, 200, 200);

        foreach ($boxes as $box) {
            $xs = array_map(fn ($p) => $p[0] * $sx, $box);
            $ys = array_map(fn ($p) => $p[1] * $sy, $box);
            $pad = (max($ys) - min($ys)) * $padding;
            $x1 = (int) max(0, floor(min($xs) - $pad));
            $y1 = (int) max(0, floor(min($ys) - $pad));
            $x2 = (int) min($w - 1, ceil(max($xs) + $pad));
            $y2 = (int) min($h - 1, ceil(max($ys) + $pad));
            imagefilledrectangle($img, $x1, $y1, $x2, $y2, $fill);
            if ($y2 - $y1 > 14 && $x2 - $x1 > 70) {
                imagestring($img, 3, $x1 + 6, (int) (($y1 + $y2) / 2) - 7, 'MASQUE', $text);
            }
        }

        self::save($img, $destination);

        return count($boxes);
    }

    /**
     * Empreinte visuelle (dHash 64 bits, 16 caractères hexadécimaux) : deux
     * photos de la même pièce, même recadrées ou recompressées, ont des
     * empreintes proches (distance de Hamming faible).
     */
    public static function dHash(string $path): ?string
    {
        try {
            $img = self::load($path);
        } catch (RuntimeException) {
            return null;
        }
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $img, 0, 0, 0, 0, 9, 8, imagesx($img), imagesy($img));

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= self::gray($small, $x, $y) > self::gray($small, $x + 1, $y) ? '1' : '0';
            }
        }

        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return $hex;
    }

    public static function hammingDistance(string $a, string $b): int
    {
        $distance = 0;
        for ($i = 0; $i < min(strlen($a), strlen($b)); $i++) {
            $distance += substr_count(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), '1');
        }

        return $distance;
    }

    private static function gray(\GdImage $img, int $x, int $y): float
    {
        $rgb = imagecolorat($img, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
    }

    private static function load(string $path): \GdImage
    {
        $data = @file_get_contents($path);
        $img = $data !== false ? @imagecreatefromstring($data) : false;
        if (! $img) {
            throw new RuntimeException('Image illisible.');
        }
        if (! imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        return $img;
    }

    private static function save(\GdImage $img, string $path): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        imagejpeg($img, $path, 88);
    }
}
