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
 * Toutes les routes passent par le middleware "signed" : l'URL n'est valable
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
