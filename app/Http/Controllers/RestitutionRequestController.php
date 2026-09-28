<?php

namespace App\Http\Controllers;

use App\Models\RestitutionRequest;
use App\Services\Restitution\DirectRestitutionService;
use Illuminate\Http\Request;

/**
 * Demandes de restitution directe, côté citoyen (propriétaire et trouveur).
 */
class RestitutionRequestController extends Controller
{
    public function __construct(private DirectRestitutionService $service) {}

    /** Mes demandes, envoyées (propriétaire) et reçues (trouveur). */
    public function index(Request $request)
    {
        $user = $request->user();

        $items = RestitutionRequest::with(['document.documentType', 'requester', 'finder'])
            ->where(fn ($q) => $q->where('requester_user_id', $user->id)->orWhere('finder_user_id', $user->id))
            ->latest('id')
            ->get()
            ->map(fn ($r) => $this->present($r, $user->id));

        return response()->json(['data' => $items]);
    }

    public function show(Request $request, RestitutionRequest $restitution)
    {
        $this->authorizeParty($request, $restitution);

        return response()->json($this->present($restitution->load(['document.documentType', 'requester', 'finder']), $request->user()->id));
    }

    public function accept(Request $request, RestitutionRequest $restitution)
    {
        $this->authorizeFinderResponse($request, $restitution);

        $restitution = $this->service->accept($restitution, $request->user());

        return response()->json([
            'message' => 'Merci ! Le propriétaire a reçu votre numéro et vous avez reçu le sien par SMS.',
            'request' => $this->present($restitution, $request->user()->id),
        ]);
    }

    public function decline(Request $request, RestitutionRequest $restitution)
    {
        $this->authorizeFinderResponse($request, $restitution);
        $data = $request->validate(['reason' => 'nullable|string|max:255']);

        $restitution = $this->service->decline($restitution, $request->user(), $data['reason'] ?? null);

        return response()->json([
            'message' => 'Réponse enregistrée. La structure la plus proche pourra récupérer le document.',
            'request' => $this->present($restitution->load(['document', 'requester', 'finder']), $request->user()->id),
        ]);
    }

    /** Le propriétaire retire sa demande. */
    public function cancel(Request $request, RestitutionRequest $restitution)
    {
        abort_unless($restitution->requester_user_id === $request->user()->id, 403);
        abort_unless(in_array($restitution->status, RestitutionRequest::ACTIVE_STATUSES, true), 409, 'Cette demande est déjà close.');

        $this->service->cancel($restitution, $request->user(), 'Retirée par le propriétaire');

        return response()->json(['message' => 'Demande retirée.']);
    }

    private function authorizeParty(Request $request, RestitutionRequest $r): void
    {
        $id = $request->user()->id;
        abort_unless($r->requester_user_id === $id || $r->finder_user_id === $id || $request->user()->isSuperAdmin(), 403);
    }

    private function authorizeFinderResponse(Request $request, RestitutionRequest $r): void
    {
        abort_unless($r->finder_user_id === $request->user()->id, 403, 'Seul le trouveur peut répondre à cette demande.');

        if ($r->isPending() && $r->expires_at?->isPast()) {
            $this->service->decline($r, null, null, expired: true);
            abort(409, 'Le délai de réponse est dépassé ; la structure la plus proche a pris le relais.');
        }
        abort_unless($r->isPending(), 409, 'Cette demande a déjà reçu une réponse.');
    }

    /** Les numéros ne sont montrés qu'après l'accord du trouveur. */
    private function present(RestitutionRequest $r, int $viewerId): array
    {
        $role   = $r->finder_user_id === $viewerId ? 'finder' : 'owner';
        $shared = $r->contact_shared_at !== null && in_array($r->status, [RestitutionRequest::STATUS_ACCEPTED, RestitutionRequest::STATUS_COMPLETED], true);
        $other  = $role === 'finder' ? $r->requester : $r->finder;
        $doc    = $r->document;

        return [
            'uuid'          => $r->uuid,
            'my_role'       => $role,
            'status'        => $r->status,
            'expires_at'    => $r->expires_at,
            'responded_at'  => $r->responded_at,
            'document'      => $doc ? [
                'uuid'          => $doc->uuid,
                'reference'     => $doc->reference,
                'document_type' => $doc->documentType?->TypeName,
                'owner_name'    => trim($doc->OwnerFirstName . ' ' . $doc->OwnerLastName),
            ] : null,
            'other_party'   => [
                'first_name' => $other?->FirstName,
                'phone'      => $shared ? $other?->Phone : null,
            ],
            'created_at'    => $r->created_at,
        ];
    }
}
