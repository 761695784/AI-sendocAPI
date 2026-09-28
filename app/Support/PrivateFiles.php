<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Emplacement des fichiers sensibles (certificats, rapports).
 *
 * Les nouveaux fichiers sont écrits sur le disque privé "local".
 * Les anciens fichiers restés sur le disque "public" sont encore lus
 * (compatibilité) mais ne sont plus jamais écrits à cet endroit.
 */
class PrivateFiles
{
    public const DISK = 'local';

    public static function put(string $relativePath, string $contents): void
    {
        Storage::disk(self::DISK)->put($relativePath, $contents);
    }

    /**
     * Chemin absolu du fichier, ou null s'il n'existe nulle part.
     */
    public static function path(?string $relativePath): ?string
    {
        if (! $relativePath) {
            return null;
        }
        foreach ([self::DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($relativePath)) {
                return Storage::disk($disk)->path($relativePath);
            }
        }

        return null;
    }

    public static function exists(?string $relativePath): bool
    {
        return self::path($relativePath) !== null;
    }

    public static function delete(?string $relativePath): void
    {
        if (! $relativePath) {
            return;
        }
        foreach ([self::DISK, 'public'] as $disk) {
            Storage::disk($disk)->delete($relativePath);
        }
    }
}
