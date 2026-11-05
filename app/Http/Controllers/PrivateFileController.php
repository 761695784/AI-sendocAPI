<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\RapportGenere;
use App\Models\User;
use App\Support\PrivateFiles;
use App\Support\QrCodeUrl;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Sert les fichiers sensibles stockés hors du dossier public.
 *
 * Les routes « files » passent par le middleware "signed" : l'URL n'est valable
 * que si l'API l'a générée elle-même, pour une durée limitée, après avoir
 * vérifié les droits de l'utilisateur.
 */
class PrivateFileController extends Controller
{
    public function documentImage(string $uuid)
    {
        $document = Document::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $media    = $document->getFirstMedia('document_image');

        abort_if(! $media || ! is_file($media->getPath()), 404);

        return response()->file($media->getPath(), [
            'Content-Type'  => $media->mime_type,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Version publique (floutée) de la photo d'un document, servie par l'API elle-même.
     * L'adresse ne dépend ni d'APP_URL ni du lien « storage » : l'image s'affiche quelle que soit
     * l'adresse à laquelle l'API est jointe. Jamais l'original : seulement les versions floutées
     * (ou la copie masquée par l'OCR, déjà sans numéros).
     */
    public function documentPublicImage(int $media, string $variant)
    {
        $item = \Spatie\MediaLibrary\MediaCollections\Models\Media::query()
            ->whereKey($media)
            ->where('model_type', (new Document())->getMorphClass())
            ->whereIn('collection_name', ['document_public', 'document_image'])
            ->firstOrFail();
        $document = Document::where('id', $item->model_id)->firstOrFail(); // publication supprimée : plus d'image
        abort_if($document->found_channel === Document::CHANNEL_QR_SCAN, 404);

        $path = null;
        foreach (array_unique([$variant, 'blurred']) as $conversion) {
            if ($item->hasGeneratedConversion($conversion) && is_file($item->getPath($conversion))) {
                $path = $item->getPath($conversion);
                break;
            }
        }
        if (! $path && $item->collection_name === 'document_public' && is_file($item->getPath())) {
            $path = $item->getPath(); // copie masquée : déjà sans numéros
        }
        if (! $path && $item->collection_name === 'document_image' && $item->getCustomProperty('public_version') !== 'masked') {
            $path = self::blurredFallback($item); // conversion absente (extension d'image, file d'attente…) : floutage à la volée
        }
        abort_unless($path, 404);

        return response()->file($path, [
            'Content-Type'  => mime_content_type($path) ?: 'image/jpeg',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /** Version très floutée générée avec GD (mise en cache), quand la conversion n'a pas été produite. */
    private static function blurredFallback(\Spatie\MediaLibrary\MediaCollections\Models\Media $media): ?string
    {
        $cache = storage_path('app/blurred-cache/' . $media->uuid . '.jpg');
        if (is_file($cache)) {
            return $cache;
        }
        $source = $media->getPath();
        if (! is_file($source) || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if (! $image) {
            return null;
        }
        // Réduction à 40 px puis agrandissement : texte et numéros illisibles.
        $w = imagesx($image);
        $h = imagesy($image);
        $sw = 40;
        $sh = max(1, (int) round($h * $sw / max(1, $w)));
        $small = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $sw, $sh, $w, $h);
        $tw = min(800, $w);
        $th = max(1, (int) round($h * $tw / max(1, $w)));
        $out = imagecreatetruecolor($tw, $th);
        imagecopyresampled($out, $small, 0, 0, 0, 0, $tw, $th, $sw, $sh);
        for ($i = 0; $i < 4; $i++) {
            imagefilter($out, IMG_FILTER_GAUSSIAN_BLUR);
        }
        @mkdir(dirname($cache), 0775, true);
        imagejpeg($out, $cache, 78);
        imagedestroy($image);
        imagedestroy($small);
        imagedestroy($out);

        return is_file($cache) ? $cache : null;
    }

    public function qrCode(string $uuid)
    {
        $user = User::where('uuid', $uuid)->firstOrFail();
        abort_if(empty($user->qr_code_token), 404);

        // PNG si l'extension imagick est présente, sinon SVG (aucune dépendance).
        $format = extension_loaded('imagick') ? 'png' : 'svg';
        $image  = QrCode::format($format)->size(300)->margin(1)->generate(QrCodeUrl::forToken($user->qr_code_token));

        return response($image, 200, [
            'Content-Type'  => $format === 'png' ? 'image/png' : 'image/svg+xml',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function certificate(string $uuid)
    {
        $certificat = \App\Models\CertificatDePerte::withTrashed()->where('uuid', $uuid)->firstOrFail();
        $path       = PrivateFiles::path($certificat->pdf_path);

        abort_if(! $path, 404);

        return response()->file($path, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $certificat->certificat_number . '.pdf"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    public function rapport(string $uuid)
    {
        $rapport = RapportGenere::where('uuid', $uuid)->firstOrFail();
        $path    = PrivateFiles::path($rapport->pdf_path);

        abort_if(! $path, 404);

        return response()->file($path, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="rapport-' . $uuid . '.pdf"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }
}
