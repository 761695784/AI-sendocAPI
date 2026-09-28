<?php

namespace App\Http\Controllers;

use App\Models\DocumentTag;
use App\Services\Tags\DocumentTagService;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * QR codes par document, côté citoyen (API v1, compte connecté).
 */
class DocumentTagController extends Controller
{
    public function __construct(private DocumentTagService $tags) {}

    public function index(Request $request)
    {
        $tags = DocumentTag::with('documentType')->where('user_id', $request->user()->id)
            ->whereIn('status', [DocumentTag::STATUS_ACTIVE, DocumentTag::STATUS_PENDING_PAYMENT, DocumentTag::STATUS_REVOKED])
            ->latest('id')->get();

        return response()->json(['data' => $tags->map(fn ($t) => $this->present($t, withQr: false))]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'document_type_id' => 'required|integer|exists:document_types,id',
            'label'            => 'nullable|string|max:60',
            'doc_number'       => 'nullable|string|max:50',
        ]);

        $tag = $this->tags->create($request->user(), $data['document_type_id'], $data['label'] ?? null, $data['doc_number'] ?? null);

        return response()->json($this->present($tag->load('documentType')), 201);
    }

    /** Fiche + image du QR à imprimer (SVG). */
    public function show(Request $request, DocumentTag $tag)
    {
        $this->authorizeOwner($request, $tag);

        return response()->json($this->present($tag->load('documentType')));
    }

    /** Nouveau QR : gratuit en pilote, sinon frais qr_renewal à régler (Wave). */
    public function renew(Request $request, DocumentTag $tag)
    {
        $this->authorizeOwner($request, $tag);
        [$new, $charge] = $this->tags->renew($tag, $request->user());

        return response()->json([
            'tag'              => $this->present($new->load('documentType'), withQr: $new->isActive()),
            'payment_required' => ! $charge->isSettled(),
            'charge'           => ChargeSummary::of($charge),
        ], $charge->isSettled() ? 201 : 402);
    }

    public function revoke(Request $request, DocumentTag $tag)
    {
        $this->authorizeOwner($request, $tag);

        return response()->json($this->present($this->tags->revoke($tag, $request->user())->load('documentType'), withQr: false));
    }

    private function authorizeOwner(Request $request, DocumentTag $tag): void
    {
        abort_unless($tag->user_id === $request->user()->id, 404);
    }

    private function present(DocumentTag $tag, bool $withQr = true): array
    {
        $qr = $withQr && $tag->isActive()
            ? 'data:image/svg+xml;base64,' . base64_encode((string) QrCode::format('svg')->size(220)->margin(1)->errorCorrection('M')->generate($tag->scanUrl()))
            : null;

        return [
            'uuid'             => $tag->uuid,
            'status'           => $tag->status,
            'document_type'    => $tag->documentType?->TypeName,
            'label'            => $tag->label,
            'doc_number_last4' => $tag->doc_number_last4,
            'scans_count'      => $tag->scans_count,
            'last_scanned_at'  => $tag->last_scanned_at,
            'activated_at'     => $tag->activated_at,
            'qr_svg'           => $qr,
            'scan_url'         => $tag->isActive() ? $tag->scanUrl() : null,
        ];
    }
}
