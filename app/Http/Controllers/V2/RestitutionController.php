<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveActiveOrganization;
use App\Http\Requests\V2\StoreRestitutionRequest;
use App\Models\Document;
use App\Models\Restitution;
use App\Services\Access\OrganizationAccess;
use App\Services\Restitution\OfficialRestitutionService;
use App\Support\PrivateFiles;
use Illuminate\Http\Request;

/**
 * Restitution officielle au guichet (espace agent).
 */
class RestitutionController extends Controller
{
    public function __construct(private OfficialRestitutionService $service, private OrganizationAccess $access) {}

    /** Préparation : ce que l'agent doit vérifier avant la remise. */
    public function prepare(Request $request, Document $document)
    {
        abort_unless($document->custodyOrganization && $this->access->can($request->user(), 'restitutions.perform', $document->custodyOrganization), 403);

        return response()->json($this->service->prepare($document));
    }

    /** Remise du document : contrôles d'identité, décharge, notifications. */
    public function store(StoreRestitutionRequest $request, Document $document)
    {
        $structure = ResolveActiveOrganization::current();
        abort_unless($structure && $this->access->can($request->user(), 'restitutions.perform', $structure), 403,
            'Vous n\'êtes pas habilité à restituer des documents pour cette structure.');

        $restitution = $this->service->restitute($document, $request->user(), $structure, $request->validated());

        return response()->json($this->present($restitution), 201);
    }

    public function index(Request $request)
    {
        $allowed = $this->access->organizationIdsWithAbility($request->user(), 'restitutions.view');
        abort_if($allowed !== null && empty($allowed), 403);

        $page = Restitution::with(['document.documentType', 'organization', 'agent'])
            ->when($allowed !== null, fn ($q) => $q->whereIn('organization_id', $allowed))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('reference'), fn ($q, $r) => $q->where('reference', $r))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('handed_over_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('handed_over_at', '<=', $d))
            ->latest('handed_over_at')
            ->paginate(25);

        $page->getCollection()->transform(fn ($r) => $this->present($r));

        return response()->json($page);
    }

    public function show(Request $request, Restitution $restitution)
    {
        $this->authorizeView($request, $restitution);

        return response()->json($this->present($restitution->load(['document.documentType', 'organization', 'agent', 'transitions'])));
    }

    /** Décharge PDF à imprimer et faire signer. */
    public function discharge(Request $request, Restitution $restitution)
    {
        $this->authorizeView($request, $restitution);
        $path = PrivateFiles::path($restitution->pdf_path);
        abort_unless($path, 404, 'Décharge introuvable.');

        return response()->file($path, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $restitution->reference . '.pdf"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    /** Joindre le scan de la décharge signée. */
    public function signedScan(Request $request, Restitution $restitution)
    {
        abort_unless($this->access->can($request->user(), 'restitutions.perform', $restitution->organization), 403);
        $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120']);

        return response()->json($this->present($this->service->attachSignedScan($restitution, $request->file('file'), $request->user())));
    }

    /** Annuler une remise erronée (responsable, dans le délai). */
    public function void(Request $request, Restitution $restitution)
    {
        abort_unless($this->access->can($request->user(), 'restitutions.void', $restitution->organization), 403);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);

        return response()->json($this->present($this->service->void($restitution, $request->user(), $data['reason'])));
    }

    private function authorizeView(Request $request, Restitution $r): void
    {
        abort_unless($this->access->can($request->user(), 'restitutions.view', $r->organization), 403);
    }

    private function present(Restitution $r): array
    {
        $r->loadMissing(['document.documentType', 'organization', 'agent']);
        $doc = $r->document;

        return [
            'uuid'              => $r->uuid,
            'reference'         => $r->reference,
            'status'            => $r->status,
            'handed_over_at'    => $r->handed_over_at,
            'organization'      => ['uuid' => $r->organization->uuid, 'name' => $r->organization->name],
            'agent'             => $r->agent ? trim($r->agent->FirstName . ' ' . $r->agent->LastName) : null,
            'document'          => $doc ? [
                'uuid' => $doc->uuid, 'reference' => $doc->reference, 'document_type' => $doc->documentType?->TypeName,
                'owner_name' => trim($doc->OwnerFirstName . ' ' . $doc->OwnerLastName), 'lifecycle_status' => $doc->lifecycle_status,
            ] : null,
            'recipient_type'    => $r->recipient_type,
            'owner'             => [
                'name'     => trim($r->owner_first_name . ' ' . $r->owner_last_name),
                'id_type'  => $r->owner_id_type,
                'id_last4' => $r->owner_id_last4,
            ],
            'proxy'             => $r->isProxy() ? [
                'name'                  => trim($r->proxy_first_name . ' ' . $r->proxy_last_name),
                'id_type'               => $r->proxy_id_type,
                'id_last4'              => $r->proxy_id_last4,
                'relationship'          => $r->proxy_relationship,
                'power_of_attorney_ref' => $r->power_of_attorney_ref,
            ] : null,
            'checks'            => $r->checks,
            'name_similarity'   => $r->name_similarity,
            'name_override'     => $r->name_override_reason,
            'verification_code' => $r->formattedVerificationCode(),
            'verification_url'  => $r->verificationUrl(),
            'discharge_url'     => url('/api/v2/restitutions/' . $r->uuid . '/discharge'),
            'signed_scan'       => $r->signed_scan_path !== null,
            'voided_at'         => $r->voided_at,
            'void_reason'       => $r->void_reason,
            'history'           => $r->relationLoaded('transitions')
                ? $r->transitions->sortBy('id')->map(fn ($t) => ['from' => $t->from, 'to' => $t->to, 'reason' => $t->reason, 'at' => $t->created_at])->values()->all()
                : null,
        ];
    }
}
