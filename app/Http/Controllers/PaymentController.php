<?php

namespace App\Http\Controllers;

use App\Models\Charge;
use App\Services\Billing\BillingManager;
use Illuminate\Http\Request;

/**
 * Paiements côté citoyen (API v1) + webhook des prestataires.
 */
class PaymentController extends Controller
{
    public function __construct(private BillingManager $billing) {}

    public function myCharges(Request $request)
    {
        $charges = Charge::where('payer_user_id', $request->user()->id)->latest('id')->limit(100)->get();

        return response()->json(['data' => $charges->map(fn ($c) => ChargeSummary::of($c))->values()]);
    }

    /** Lien de paiement Wave pour un de ses frais en attente. */
    public function checkout(Request $request, Charge $charge)
    {
        abort_unless($charge->payer_user_id === $request->user()->id, 404);

        try {
            $payment = $this->billing->checkout($charge, $request->user()->Phone);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        return response()->json(['payment' => $payment->uuid, 'checkout_url' => $payment->checkout_url, 'charge' => ChargeSummary::of($charge->fresh())]);
    }

    /** POST /api/webhooks/{provider} — signature vérifiée, traitement idempotent. */
    public function webhook(Request $request, string $provider)
    {
        abort_unless(in_array($provider, ['wave'], true), 404);
        $result = $this->billing->handleWebhook($provider, $request);

        return response()->json(['result' => $result], $result === 'invalid_signature' ? 401 : 200);
    }
}
