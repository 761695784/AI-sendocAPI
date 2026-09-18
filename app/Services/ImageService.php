<?php
namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Log;

class ImageService
{
    public function convertToWebP($file, $path, $quality = 90)
    {
        try {
            Log::info('Tentative de conversion de l’image en WebP');

            $image = Image::make($file)->encode('webp', $quality);
            $webpPath = $path . '.webp';
            Storage::disk('public')->put($webpPath, $image->stream());

            $url = Storage::url($webpPath);
            Log::info('Image convertie et sauvegardée avec succès : ' . $url);

            return $url;
        } catch (\Exception $e) {
            Log::error('Erreur lors de la conversion de l’image en WebP : ' . $e->getMessage());
            throw $e;
        }
    }
}

