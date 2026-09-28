<?php

namespace App\Services\Billing\Providers;

use App\Models\Charge;
use App\Models\Payment;
use Illuminate\Http\Request;

/** Prestataire de paiement en ligne (Wave aujourd'hui, Orange Money demain). */
interface PaymentProvider
{
    public function name(): string;

    /**
     * Crée la session de paiement chez le prestataire.
     * @return array{reference: string, url: string}
     */
    public function createCheckout(Payment $payment, Charge $charge, ?string $payerPhone = null): array;

    /**
     * Vérifie et lit une notification du prestataire (null si signature invalide).
     * @return array{reference: ?string, client_reference: ?string, status: string, transaction_id: ?string, amount: ?int, event: ?string}|null
     */
    public function parseWebhook(Request $request): ?array;
}
