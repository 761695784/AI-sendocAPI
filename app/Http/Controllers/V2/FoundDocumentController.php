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
use Illuminate\Support\Facades\DB;
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

        $scan = $request->input('ocr_scan_id') ? \App\Models\OcrScan::where('uuid', $request->input('ocr_scan_id'))->first() : null;
        $document = $this->service->depositAtCounter($request->user(), $structure, $request->validated(), $request->file('image'), $scan);

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

    /**
     * Suppression définitive (sans corbeille) d'un document enregistré au guichet.
     * Refusée dès que le propriétaire est identifié ou que la restitution a commencé :
     * la trace doit alors être conservée.
     */
    public function destroy(Request $request, Document $document)
    {
        Gate::authorize('destroyCustody', $document);

        $locked = $document->lifecycle_status !== Document::LIFECYCLE_IN_CUSTODY
            || $document->matches()->where('status', \App\Models\DocumentMatch::STATUS_CONFIRMED)->exists()
            || \App\Models\Restitution::where('document_id', $document->id)->exists()
            || $document->restitutionRequests()->exists();
        if ($locked) {
            return response()->json([
                'success' => false,
                'message' => 'Ce document ne peut plus être supprimé : son propriétaire est identifié ou une restitution est en cours.',
            ], 409);
        }

        $reference = $document->reference;
        DB::transaction(function () use ($document) {
            $document->transitions()->delete();
            \App\Models\VigilanceAlert::where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->delete();
            $document->forceDelete(); // médias, correspondances non confirmées et collectes supprimés avec lui
        });

        activity()->causedBy($request->user())
            ->withProperties(['reference' => $reference, 'organization' => ResolveActiveOrganization::current()?->uuid])
            ->log('Document trouvé supprimé définitivement');

        \Illuminate\Support\Facades\Cache::forget('publications_by_department');
        \Illuminate\Support\Facades\Cache::forget('publications_by_type');

        return response()->json(['success' => true, 'message' => "Le document {$reference} a été supprimé définitivement."]);
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
