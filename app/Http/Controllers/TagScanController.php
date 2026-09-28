<?php

namespace App\Http\Controllers;

use App\Models\DocumentTag;
use App\Services\Tags\DocumentTagService;
use Illuminate\Http\Request;

/**
 * Scan public d'un QR de document (aucun compte requis pour le trouveur).
 */
class TagScanController extends Controller
{
    public function __construct(private DocumentTagService $tags) {}

    public function show(string $token)
    {
        $view = $this->tags->publicView(DocumentTag::with('documentType')->where('token', $token)->first());

        return response()->json($view, $view['valid'] ? 200 : 404);
    }

    public function report(Request $request, string $token)
    {
        $tag  = DocumentTag::where('token', $token)->first();
        abort_unless($tag && $tag->isActive(), 404, 'QR code inconnu ou désactivé.');

        $data = $request->validate([
            'finder_phone'        => ['required', 'string', 'max:20', function ($attr, $value, $fail) {
                if (! \App\Services\SmsService::normalizeSenegalPhone($value)) {
                    $fail('Numéro invalide (format sénégalais attendu, ex. 77 123 45 67).');
                }
            }],
            'finder_name'         => 'nullable|string|max:100',
            'latitude'            => 'nullable|numeric|between:12,17|required_with:longitude',
            'longitude'           => 'nullable|numeric|between:-18,-11|required_with:latitude',
            'territory_code'      => 'nullable|string|exists:territories,code',
            'place'               => 'nullable|string|max:255',
            'allow_owner_contact' => 'sometimes|boolean',
            'contact_consent'     => 'sometimes|boolean',
        ]);

        return response()->json($this->tags->reportFound($tag, $data, $request->ip()));
    }
}
