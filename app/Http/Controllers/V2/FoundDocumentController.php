<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveActiveOrganization;
use App\Http\Requests\V2\StoreFoundDocumentRequest;
use App\Http\Resources\V2\FoundDocumentResource;
use App\Models\Document;
use App\Services\Access\OrganizationAccess;
use App\Services\FoundDocuments\FoundDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Documents trouvés sous la garde des structures (espace agent).
 */
class FoundDocumentController extends Controller
{
    public function __construct(private FoundDocumentService $service, private OrganizationAccess $access) {}

    /** Filtres : status (lifecycle), reference, type, from, to. */
    public function index(Request $request)
    {
        Gate::authorize('viewInstitutional', Document::class);

        $allowed = $this->access->organizationIdsWithAbility($request->user(), 'found_documents.view');

        $page = Document::query()
            ->with(['documentType', 'custodyOrganization', 'depositedBy', 'matches'])
            ->whereNotNull('custody_organization_id')
            ->when($allowed !== null, fn ($q) => $q->whereIn('custody_organization_id', $allowed))
            ->when($request->query('status'), fn ($q, $s) => $q->where('lifecycle_status', $s))
            ->when($request->query('reference'), fn ($q, $r) => $q->where('reference', $r))
            ->when($request->query('type'), fn ($q, $t) => $q->where('document_type_id', $t))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate(25);

        $page->getCollection()->transform(fn ($d) => (new FoundDocumentResource($d))->resolve($request));

        return response()->json($page);
    }

    public function store(StoreFoundDocumentRequest $request)
    {
        $structure = ResolveActiveOrganization::current();
        abort_unless($structure, 403, 'Aucune structure active.');
        $structure->loadMissing('type');
        Gate::authorize('depositAtCounter', [Document::class, $structure]);

        $document = $this->service->depositAtCounter($request->user(), $structure, $request->validated(), $request->file('image'));

        return response()->json((new FoundDocumentResource($document))->resolve($request), 201);
    }

    public function show(Request $request, Document $document)
    {
        Gate::authorize('viewCustody', $document);

        activity()->causedBy($request->user())->performedOn($document)
            ->withProperties(['reference' => $document->reference])
            ->log('Consultation d\'un document trouvé');

        return response()->json((new FoundDocumentResource(
            $document->load(['documentType', 'custodyOrganization', 'depositedBy', 'finder', 'matches'])
        ))->resolve($request));
    }

    /** Le trouveur remet à la structure un document qu'il avait publié en ligne. */
    public function takeCustody(Request $request, Document $document)
    {
        $structure = ResolveActiveOrganization::current();
        abort_unless($structure, 403, 'Aucune structure active.');
        $structure->loadMissing('type');
        Gate::authorize('takeCustody', [$document, $structure]);

        $document = $this->service->takeCustody($document, $request->user(), $structure);

        return response()->json((new FoundDocumentResource($document->load('documentType')))->resolve($request));
    }
}
