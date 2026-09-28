<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\V2\CollectionRequestResource;
use App\Models\CollectionRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\OrganizationAccess;
use App\Services\Collections\CollectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Collecte des documents publiés par les citoyens (espace agent).
 */
class CollectionController extends Controller
{
    private const RELATIONS = ['document.documentType', 'document.territory', 'organization', 'pickupAgent'];

    public function __construct(private CollectionService $service, private OrganizationAccess $access) {}

    /** Par défaut : collectes en cours, prioritaires puis plus anciennes d'abord. */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', CollectionRequest::class);

        $allowed  = $this->access->organizationIdsWithAbility($request->user(), 'collections.view');
        $statuses = array_filter(explode(',', (string) $request->query('status', implode(',', CollectionRequest::OPEN_STATUSES))));

        $page = CollectionRequest::query()
            ->with(self::RELATIONS)
            ->when($allowed !== null, fn ($q) => $q->whereIn('organization_id', $allowed))
            ->whereIn('status', $statuses)
            ->orderByRaw('owner_identified_at IS NULL')
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->paginate(25);

        $page->getCollection()->transform(fn ($c) => (new CollectionRequestResource($c))->resolve($request));

        return response()->json($page);
    }

    /** Fiche complète avec le numéro du trouveur : consultation tracée. */
    public function show(Request $request, CollectionRequest $collection)
    {
        Gate::authorize('view', $collection);

        activity()->causedBy($request->user())->performedOn($collection)
            ->withProperties(['document' => $collection->document?->reference])
            ->log('Consultation du contact du trouveur');

        return $this->respond($request, $collection, withHistory: true);
    }

    public function contact(Request $request, CollectionRequest $collection)
    {
        Gate::authorize('manage', $collection);
        $data = $request->validate([
            'outcome' => ['required', Rule::in([CollectionRequest::OUTCOME_REACHED, CollectionRequest::OUTCOME_NO_ANSWER, CollectionRequest::OUTCOME_WRONG_NUMBER])],
            'note'    => 'nullable|string|max:255',
        ]);

        return $this->respond($request, $this->service->recordContact($collection, $request->user(), $data['outcome'], $data['note'] ?? null));
    }

    public function plan(Request $request, CollectionRequest $collection)
    {
        Gate::authorize('manage', $collection);
        $data = $request->validate([
            'method'            => ['required', Rule::in([CollectionRequest::METHOD_DROP_OFF, CollectionRequest::METHOD_AGENT_PICKUP])],
            'scheduled_at'      => 'nullable|date|after_or_equal:today',
            'meeting_place'     => 'nullable|string|max:255',
            'pickup_agent_uuid' => 'nullable|uuid|exists:users,uuid',
        ]);

        $pickupAgent = null;
        if (! empty($data['pickup_agent_uuid'])) {
            $pickupAgent = User::where('uuid', $data['pickup_agent_uuid'])->firstOrFail();
            if (! $this->access->memberships($pickupAgent)->contains('organization_id', $collection->organization_id)) {
                throw ValidationException::withMessages(['pickup_agent_uuid' => 'Cet agent n\'appartient pas à la structure.']);
            }
        }

        return $this->respond($request, $this->service->plan(
            $collection, $request->user(), $data['method'], $data['scheduled_at'] ?? null, $data['meeting_place'] ?? null, $pickupAgent
        ));
    }

    /** Le document est arrivé à la structure : il passe sous sa garde. */
    public function collected(Request $request, CollectionRequest $collection)
    {
        Gate::authorize('manage', $collection);

        return $this->respond($request, $this->service->markCollected($collection, $request->user()));
    }

    public function transfer(Request $request, CollectionRequest $collection)
    {
        Gate::authorize('manage', $collection);
        $data = $request->validate([
            'organization_uuid' => 'required|uuid|exists:organizations,uuid',
            'reason'            => 'required|string|min:5|max:255',
        ]);

        $target = Organization::where('uuid', $data['organization_uuid'])->firstOrFail();

        return $this->respond($request, $this->service->transfer($collection, $request->user(), $target, $data['reason']));
    }

    /** « Nous ne pouvons pas » : le dossier part à la structure suivante la plus proche. */
    public function decline(Request $request, CollectionRequest $collection)
    {
        Gate::authorize('manage', $collection);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);

        return $this->respond($request, $this->service->decline($collection, $request->user(), $data['reason']), gate: false);
    }

    public function cancel(Request $request, CollectionRequest $collection)
    {
        Gate::authorize('manage', $collection);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);

        return $this->respond($request, $this->service->cancel($collection, $request->user(), $data['reason']));
    }

    private function respond(Request $request, CollectionRequest $collection, bool $withHistory = false, bool $gate = true)
    {
        $collection->load(array_merge(self::RELATIONS, $withHistory ? ['transitions'] : []));

        $resource = new CollectionRequestResource($collection);
        // Après un refus, le dossier appartient à une autre structure : plus de numéro.
        $resource->withPhone = ! $gate ? false : Gate::allows('view', $collection);

        return response()->json($resource->resolve($request));
    }
}
