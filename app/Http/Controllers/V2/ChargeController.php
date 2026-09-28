<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\ChargeSummary;
use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Services\Access\OrganizationAccess;
use App\Services\Billing\BillingManager;
use Illuminate\Http\Request;

/**
 * Frais au guichet (espace agent) : encaissement, exonération, lien Wave.
 */
class ChargeController extends Controller
{
    public function __construct(private BillingManager $billing, private OrganizationAccess $access) {}

    /** Par défaut : les frais en attente de la structure. */
    public function index(Request $request)
    {
        $user = $request->user();
        $ids  = $user->isSuperAdmin() ? null : array_values(array_unique(array_merge(
            $this->access->organizationIdsWithAbility($user, 'billing.collect'),
            $this->access->organizationIdsWithAbility($user, 'billing.view'),
        )));
        abort_if($ids !== null && empty($ids), 403);

        $page = Charge::with(['service', 'organization', 'payments'])
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->whereIn('status', array_filter(explode(',', (string) $request->query('status', Charge::STATUS_PENDING))))
            ->latest('id')->paginate(30);

        $page->getCollection()->transform(fn ($c) => ChargeSummary::of($c));

        return response()->json($page);
    }

    public function show(Request $request, Charge $charge)
    {
        $this->authorizeAbility($request, $charge, ['billing.collect', 'billing.view']);

        return response()->json(ChargeSummary::of($charge, detailed: true));
    }

    /** Espèces reçues au guichet → reçu FA-… et démarche débloquée. */
    public function cash(Request $request, Charge $charge)
    {
        $this->authorizeAbility($request, $charge, ['billing.collect']);
        $data = $request->validate(['amount_received' => 'nullable|integer|min:0']);

        $result = $this->billing->payCash($charge, $request->user(), $data['amount_received'] ?? null);

        return response()->json(ChargeSummary::of($result['charge'], detailed: true) + ['change_due' => $result['change_due']]);
    }

    public function exempt(Request $request, Charge $charge)
    {
        $this->authorizeAbility($request, $charge, ['billing.exempt']);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);

        return response()->json(ChargeSummary::of($this->billing->exempt($charge, $request->user(), $data['reason']), detailed: true));
    }

    public function cancel(Request $request, Charge $charge)
    {
        $this->authorizeAbility($request, $charge, ['billing.exempt']);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255']);

        return response()->json(ChargeSummary::of($this->billing->cancel($charge, $request->user(), $data['reason']), detailed: true));
    }

    /** Le citoyen paie par Wave au guichet (lien ou QR à scanner avec son téléphone). */
    public function checkout(Request $request, Charge $charge)
    {
        $this->authorizeAbility($request, $charge, ['billing.collect']);
        $data = $request->validate(['payer_phone' => 'nullable|string|max:20']);

        try {
            $payment = $this->billing->checkout($charge, $data['payer_phone'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        return response()->json(['payment' => $payment->uuid, 'checkout_url' => $payment->checkout_url]);
    }

    private function authorizeAbility(Request $request, Charge $charge, array $abilities): void
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            return;
        }
        abort_unless($charge->organization, 403);
        foreach ($abilities as $ability) {
            if ($this->access->can($user, $ability, $charge->organization)) {
                return;
            }
        }
        abort(403);
    }
}
