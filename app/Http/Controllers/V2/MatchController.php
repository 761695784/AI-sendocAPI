<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\V2\MatchResource;
use App\Models\DocumentMatch;
use App\Services\Access\OrganizationAccess;
use App\Services\Matching\MatchReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * File de revue des correspondances (espace agent).
 */
class MatchController extends Controller
{
    private const RELATIONS = [
        'item.documentType', 'declaration.organization', 'document.documentType',
        'document.custodyOrganization', 'organization', 'reviewer',
    ];

    public function __construct(private MatchReviewService $review, private OrganizationAccess $access) {}

    /** Par défaut : correspondances à valider (pending_review), meilleur score d'abord. */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', DocumentMatch::class);

        $allowed  = $this->access->organizationIdsWithAbility($request->user(), 'matches.view');
        $statuses = array_filter(explode(',', (string) $request->query('status', DocumentMatch::STATUS_PENDING_REVIEW)));

        $page = DocumentMatch::query()
            ->with(self::RELATIONS)
            ->when($allowed !== null, fn ($q) => $q->whereIn('organization_id', $allowed))
            ->whereIn('status', $statuses)
            ->orderByDesc('score')
            ->latest()
            ->paginate(25);

        $page->getCollection()->transform(fn ($m) => (new MatchResource($m))->resolve($request));

        return response()->json($page);
    }

    public function show(Request $request, DocumentMatch $match)
    {
        Gate::authorize('view', $match);

        return response()->json((new MatchResource($match->load(self::RELATIONS)))->resolve($request));
    }

    public function confirm(Request $request, DocumentMatch $match)
    {
        Gate::authorize('review', $match);
        $data = $request->validate(['note' => 'nullable|string|max:255']);

        $match = $this->review->confirm($match, $request->user(), $data['note'] ?? null);

        return response()->json((new MatchResource($match->load(self::RELATIONS)))->resolve($request));
    }

    public function reject(Request $request, DocumentMatch $match)
    {
        Gate::authorize('review', $match);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);

        $match = $this->review->reject($match, $request->user(), $data['reason']);

        return response()->json((new MatchResource($match->load(self::RELATIONS)))->resolve($request));
    }
}
