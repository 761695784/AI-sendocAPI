<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\VigilanceAlert;
use App\Services\Access\OrganizationAccess;
use App\Services\Vigilance\VigilanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alertes de vigilance (doublons, fraude) : lues et tranchées par les
 * responsables de structure / d'institution, ou le super admin.
 * Les alertes sans structure (compte citoyen, publication en ligne) ne sont
 * visibles que du super admin.
 */
class VigilanceController extends Controller
{
    public function __construct(private OrganizationAccess $access, private VigilanceService $vigilance) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'   => 'nullable|in:open,confirmed,dismissed',
            'level'    => 'nullable|in:info,warning,high',
            'kind'     => 'nullable|string|max:40',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->scoped($request)
            ->with(['organization:id,uuid,name', 'reviewer:id,FirstName,LastName'])
            ->where('status', $data['status'] ?? VigilanceAlert::STATUS_OPEN)
            ->when($data['level'] ?? null, fn ($q, $level) => $q->where('level', $level))
            ->when($data['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->orderByRaw("CASE level WHEN 'high' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->latest('id');

        $page = $query->paginate($data['per_page'] ?? 25);

        return response()->json([
            'data'    => collect($page->items())->map(fn ($a) => $this->present($a)),
            'summary' => $this->scoped($request)->open()->selectRaw('level, count(*) as n')->groupBy('level')->pluck('n', 'level'),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, VigilanceAlert $alert): JsonResponse
    {
        $this->authorizeAlert($request, $alert, 'vigilance.view');

        return response()->json($this->present($alert->load(['organization:id,uuid,name', 'reviewer:id,FirstName,LastName'])));
    }

    public function dismiss(Request $request, VigilanceAlert $alert): JsonResponse
    {
        return $this->decide($request, $alert, VigilanceAlert::STATUS_DISMISSED);
    }

    public function confirm(Request $request, VigilanceAlert $alert): JsonResponse
    {
        return $this->decide($request, $alert, VigilanceAlert::STATUS_CONFIRMED);
    }

    private function decide(Request $request, VigilanceAlert $alert, string $decision): JsonResponse
    {
        $this->authorizeAlert($request, $alert, 'vigilance.review');
        abort_unless($alert->status === VigilanceAlert::STATUS_OPEN, 409, 'Alerte déjà traitée.');
        $data = $request->validate(['note' => 'required|string|min:5|max:500']);

        $this->vigilance->review($alert, $request->user(), $decision, $data['note']);

        return response()->json($this->present($alert->fresh(['organization:id,uuid,name', 'reviewer:id,FirstName,LastName'])));
    }

    private function scoped(Request $request)
    {
        $ids = $this->access->organizationIdsWithAbility($request->user(), 'vigilance.view');

        return VigilanceAlert::query()->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids));
    }

    private function authorizeAlert(Request $request, VigilanceAlert $alert, string $ability): void
    {
        $user = $request->user();
        abort_unless(
            $user->isSuperAdmin() || ($alert->organization && $this->access->can($user, $ability, $alert->organization)),
            404
        );
    }

    private function present(VigilanceAlert $a): array
    {
        $subject = $a->subject;

        return [
            'uuid'         => $a->uuid,
            'kind'         => $a->kind,
            'label'        => config("vigilance.labels.{$a->kind}", $a->kind),
            'level'        => $a->level,
            'status'       => $a->status,
            'message'      => $a->details['message'] ?? null,
            'details'      => collect($a->details ?? [])->except(['message', 'other_id'])->all(),
            'subject'      => [
                'type'      => class_basename($a->subject_type),
                'uuid'      => $subject->uuid ?? null,
                'reference' => $subject->reference ?? null,
            ],
            'organization' => $a->organization?->only(['uuid', 'name']),
            'reviewed_by'  => $a->reviewer ? trim($a->reviewer->FirstName . ' ' . $a->reviewer->LastName) : null,
            'reviewed_at'  => $a->reviewed_at?->toIso8601String(),
            'review_note'  => $a->review_note,
            'created_at'   => $a->created_at?->toIso8601String(),
        ];
    }
}
