<?php

namespace App\Services\Ocr;

use App\Models\Document;
use App\Models\DocumentNumber;
use App\Models\OcrScan;
use App\Models\User;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\DocumentOcr;
use App\Support\SensitiveData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scan d'une pièce : photo → lecture (service OCR) → interprétation
 * (DocumentReader) → photo publique avec numéros masqués.
 *
 * Le scan est une étape AVANT la publication : la personne vérifie et
 * corrige les champs proposés, puis publie en citant le scan.
 */
class OcrScanService
{
    public function __construct(private DocumentOcr $ocr, private DocumentReader $reader) {}

    public function available(): bool
    {
        return $this->ocr->available();
    }

    /**
     * @throws AiUnavailableException
     */
    public function scan(UploadedFile $file, ?User $user, string $purpose = OcrScan::PURPOSE_FOUND_DOCUMENT): OcrScan
    {
        $uuid  = (string) Str::uuid();
        $disk  = Storage::disk('local');
        $image = "ocr/scans/{$uuid}.jpg";

        [$w, $h] = ImageTools::normalize($file->getRealPath(), $disk->path($image));

        try {
            $ocr = $this->ocr->read($disk->path($image));
        } catch (AiUnavailableException $e) {
            $disk->delete($image);
            throw $e;
        }

        // Le service a remis la photo à l'endroit : on fait de même sur notre copie.
        if ($ocr['rotation']) {
            [$w, $h] = ImageTools::rotate($disk->path($image), $ocr['rotation']);
        }

        $result = $this->reader->read($ocr);

        // Pièce présentée au guichet : on ne garde AUCUNE image, seulement le résultat (chiffré, 60 min).
        if ($purpose === OcrScan::PURPOSE_IDENTITY_CHECK) {
            $disk->delete($image);
            unset($result['sensitive_lines']);

            return OcrScan::create([
                'uuid'             => $uuid,
                'user_id'          => $user?->id,
                'purpose'          => $purpose,
                'status'           => $result['quality']['status'],
                'engine'           => $ocr['engine'] ?? null,
                'result'           => $result,
                'quality_score'    => $result['quality']['score'],
                'document_type_id' => $result['document_type']['id'] ?? null,
                'expires_at'       => now()->addMinutes((int) config('ai.ocr.scan_ttl_minutes', 60)),
            ]);
        }

        $masked = "ocr/scans/{$uuid}_public.jpg";
        $regions = ImageTools::mask(
            $disk->path($image), $disk->path($masked), $result['sensitive_lines'],
            $ocr['width'] ?: $w, $ocr['height'] ?: $h, (float) config('ocr.mask_padding', 0.35)
        );
        unset($result['sensitive_lines']);

        return OcrScan::create([
            'uuid'             => $uuid,
            'user_id'          => $user?->id,
            'purpose'          => $purpose,
            'status'           => $result['quality']['status'],
            'engine'           => $ocr['engine'] ?? null,
            'image_path'       => $image,
            'masked_path'      => $masked,
            'result'           => $result,
            'quality_score'    => $result['quality']['score'],
            'masked_regions'   => $regions,
            'document_type_id' => $result['document_type']['id'] ?? null,
            'expires_at'       => now()->addMinutes((int) config('ai.ocr.scan_ttl_minutes', 60)),
        ]);
    }

    /**
     * Publication d'un document trouvé à partir d'un scan : photo originale
     * (privée) + photo masquée (base des versions publiques), numéros
     * supplémentaires, date de naissance, corrections faites par la personne.
     */
    public function attachToDocument(OcrScan $scan, Document $document, array $submitted): void
    {
        $disk = Storage::disk('local');

        $document->addMedia($disk->path($scan->image_path))
            ->preservingOriginal()
            ->withCustomProperties(['ocr_scan' => $scan->uuid, 'public_version' => 'masked'])
            ->toMediaCollection('document_image');

        if ($scan->masked_path && $disk->exists($scan->masked_path)) {
            $document->addMedia($disk->path($scan->masked_path))
                ->preservingOriginal()
                ->withCustomProperties(['ocr_scan' => $scan->uuid, 'masked_regions' => $scan->masked_regions])
                ->toMediaCollection('document_public');
        }

        $this->recordReading($scan, $document, $submitted);
        $scan->deleteImages();
        $scan->forceFill([
            'status'      => OcrScan::STATUS_USED,
            'used_at'     => now(),
            'document_id' => $document->id,
            'image_path'  => null,
            'masked_path' => null,
        ])->save();

        app(\App\Services\Vigilance\VigilanceService::class)->checkOcr($document, $scan);
    }

    /**
     * Photo publiée sans scan préalable : si le service OCR est branché et
     * AI_OCR_MASK_ON_UPLOAD activé, on la lit quand même pour masquer les numéros.
     */
    public function maskUploadedImage(UploadedFile $file, Document $document, ?User $user): bool
    {
        if (! config('ai.ocr.mask_on_upload') || ! $this->available()) {
            return false;
        }

        try {
            $scan = $this->scan($file, $user);
            $disk = Storage::disk('local');
            $document->addMedia($disk->path($scan->masked_path))
                ->preservingOriginal()
                ->withCustomProperties(['ocr_scan' => $scan->uuid, 'masked_regions' => $scan->masked_regions])
                ->toMediaCollection('document_public');
            $this->recordReading($scan, $document, [], alsoPrimary: false);
            $scan->deleteImages();
            $scan->forceFill(['status' => OcrScan::STATUS_USED, 'used_at' => now(), 'document_id' => $document->id, 'image_path' => null, 'masked_path' => null])->save();

            return true;
        } catch (Throwable $e) {
            Log::info('Masquage automatique impossible : ' . $e->getMessage());

            return false;
        }
    }

    /** Numéros supplémentaires, date de naissance et écarts entre lecture et saisie. */
    private function recordReading(OcrScan $scan, Document $document, array $submitted, bool $alsoPrimary = true): void
    {
        $result = $scan->result ?? [];

        foreach ($result['numbers'] ?? [] as $number) {
            $normalized = SensitiveData::normalizeDocNumber($number['value']);
            $hash = SensitiveData::hash($normalized);
            if (! $hash || $hash === $document->doc_number_hash) {
                continue;
            }
            DocumentNumber::firstOrCreate(
                ['document_id' => $document->id, 'hash' => $hash],
                ['kind' => $number['kind'], 'last4' => SensitiveData::last4($normalized), 'source' => 'ocr']
            );
        }

        if (! $document->birth_date_in_doc && ($birth = $result['fields']['birth_date']['value'] ?? null)) {
            $document->forceFill(['birth_date_in_doc' => $birth])->saveQuietly();
        }

        if (! $alsoPrimary) {
            return;
        }

        // Ce que la personne a changé par rapport à la lecture (qualité de l'OCR, signal de vigilance).
        $map = [
            'first_name' => 'OwnerFirstName',
            'last_name'  => 'OwnerLastName',
            'doc_number' => 'DocIdentification',
        ];
        $corrections = [];
        foreach ($map as $field => $input) {
            $read  = $result['fields'][$field]['value'] ?? null;
            $given = $submitted[$input] ?? null;
            if ($read !== null && $given !== null
                && DocumentReader::compact((string) $read) !== DocumentReader::compact((string) $given)) {
                $corrections[] = $field;
            }
        }
        if (isset($submitted['document_type_id'], $result['document_type']['id'])
            && (int) $submitted['document_type_id'] !== (int) $result['document_type']['id']) {
            $corrections[] = 'document_type';
        }
        $scan->corrections = $corrections;

        activity()
            ->performedOn($document)
            ->causedBy($scan->user_id)
            ->withProperties([
                'ocr_scan'      => $scan->uuid,
                'quality_score' => $scan->quality_score,
                'mrz_valid'     => $result['mrz']['valid'] ?? null,
                'checks'        => array_column($result['checks'] ?? [], 'code'),
                'corrections'   => $corrections,
            ])
            ->log('Pièce lue par OCR');
    }

    /** Supprime les scans jamais publiés (et leurs images). */
    public function purgeExpired(): int
    {
        $count = 0;
        OcrScan::whereNull('used_at')->where('expires_at', '<', now())->each(function (OcrScan $scan) use (&$count) {
            $scan->delete();
            $count++;
        });
        // Scans publiés : on ne garde que la trace (plus d'image, résultat effacé après 30 jours).
        OcrScan::whereNotNull('used_at')->where('used_at', '<', now()->subDays(30))->whereNotNull('result')
            ->update(['result' => null]);

        return $count;
    }
}
