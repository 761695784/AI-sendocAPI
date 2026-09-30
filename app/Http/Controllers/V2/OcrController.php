<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\OcrScan;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ocr\OcrScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Scan d'une pièce trouvée : la photo est lue, les champs sont proposés,
 * la personne vérifie puis publie avec `ocr_scan_id` (POST /api/documents).
 */
class OcrController extends Controller
{
    public function __construct(private OcrScanService $scans) {}

    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'image'   => 'required|file|mimes:jpeg,jpg,png,webp|max:' . config('ai.ocr.max_upload_kb', 8192),
            'purpose' => 'nullable|in:' . implode(',', OcrScan::PURPOSES),
        ]);

        if (! $this->scans->available()) {
            return response()->json([
                'success' => false,
                'message' => 'La lecture automatique n\'est pas disponible pour le moment. Remplissez le formulaire à la main.',
            ], 503);
        }

        try {
            $scan = $this->scans->scan($request->file('image'), $request->user(), $request->input('purpose', OcrScan::PURPOSE_FOUND_DOCUMENT));
        } catch (AiUnavailableException) {
            return response()->json([
                'success' => false,
                'message' => 'Le service de lecture ne répond pas. Réessayez dans une minute ou remplissez le formulaire à la main.',
            ], 503);
        } catch (RuntimeException) {
            return response()->json(['success' => false, 'message' => 'Image illisible. Reprenez la photo.'], 422);
        }

        return response()->json($this->payload($scan), 201);
    }

    public function show(Request $request, OcrScan $scan): JsonResponse
    {
        abort_unless($scan->user_id === $request->user()->id, 404);

        return response()->json($this->payload($scan));
    }

    /** Photo masquée (aperçu de ce que verra le public) — URL signée, avant publication. */
    public function preview(OcrScan $scan)
    {
        abort_unless($scan->masked_path && Storage::disk('local')->exists($scan->masked_path), 404);

        return response()->file(Storage::disk('local')->path($scan->masked_path), [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Type de pièce lu → type de pièce d'identité de la restitution. */
    public static function idType(?string $documentType): ?string
    {
        return $documentType ? (config('ocr.restitution_id_types')[$documentType] ?? 'autre') : null;
    }

    private function payload(OcrScan $scan): array
    {
        $r = $scan->result ?? [];
        $value = fn (string $f) => $r['fields'][$f]['value'] ?? null;
        $usable = $scan->isUsable();

        return [
            'scan_id'        => $scan->uuid,
            'status'         => $scan->status,
            'usable'         => $usable,
            'expires_at'     => $scan->expires_at?->toIso8601String(),
            'quality'        => $r['quality'] ?? null,
            'document_type'  => $r['document_type'] ?? null,
            'fields'         => $r['fields'] ?? [],
            'numbers'        => $r['numbers'] ?? [],
            'mrz'            => $r['mrz'] ?? null,
            'checks'         => $r['checks'] ?? [],
            'masked_regions' => $scan->masked_regions,
            'preview_url'    => $usable && $scan->masked_path ? URL::temporarySignedRoute('ocr.preview', now()->addMinutes(15), ['scan' => $scan->uuid]) : null,
            'purpose'        => $scan->purpose,
            // Pré-remplissage direct du formulaire suivant.
            'prefill'        => $scan->purpose === OcrScan::PURPOSE_IDENTITY_CHECK
                ? [   // restitution au guichet : owner.* ou proxy.* (POST /api/v2/found-documents/{uuid}/restitution)
                    'ocr_scan_id' => $scan->uuid,
                    'first_name'  => $value('first_name'),
                    'last_name'   => $value('last_name'),
                    'birth_date'  => $value('birth_date'),
                    'id_type'     => self::idType($r['document_type']['name'] ?? null),
                    'id_number'   => $value('doc_number'),
                ]
                : [   // publication d'un document trouvé (POST /api/documents ou dépôt au guichet)
                    'ocr_scan_id'       => $scan->uuid,
                    'document_type_id'  => $r['document_type']['id'] ?? null,
                    'OwnerFirstName'    => $value('first_name'),
                    'OwnerLastName'     => $value('last_name'),
                    'DocIdentification' => $value('doc_number'),
                    'birth_date'        => $value('birth_date'),
                ],
        ];
    }
}
