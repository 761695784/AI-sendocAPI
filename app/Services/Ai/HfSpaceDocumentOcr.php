<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Service Python « doctrack-ocr » (RapidOCR) hébergé sur un Hugging Face Space : POST {url}/ocr. */
class HfSpaceDocumentOcr implements DocumentOcr
{
    public function available(): bool
    {
        return (bool) config('ai.ocr.hf_space.url');
    }

    public function read(string $imagePath): array
    {
        if (! $this->available()) {
            throw new AiUnavailableException('AI_OCR_URL manquant.');
        }

        try {
            $request = Http::timeout(config('ai.ocr.hf_space.timeout', 90))->acceptJson();
            if ($token = config('ai.ocr.hf_space.token')) {
                $request = $request->withToken($token);
            }
            $response = $request
                ->attach('file', fopen($imagePath, 'r'), 'scan.jpg', ['Content-Type' => 'image/jpeg'])
                ->post(rtrim(config('ai.ocr.hf_space.url'), '/') . '/ocr');
        } catch (Throwable $e) {
            Log::warning('OCR injoignable : ' . $e->getMessage());
            throw new AiUnavailableException('Service OCR injoignable.', 0, $e);
        }

        if (! $response->successful() || ! is_array($response->json('lines'))) {
            Log::warning('OCR en erreur', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);
            throw new AiUnavailableException('Service OCR en erreur (' . $response->status() . ').');
        }

        return [
            'engine'   => $response->json('engine'),
            'rotation' => (int) $response->json('rotation', 0),
            'width'    => (int) $response->json('width', 0),
            'height'   => (int) $response->json('height', 0),
            'lines'    => $response->json('lines'),
        ];
    }
}
