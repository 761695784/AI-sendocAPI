<?php

namespace App\Services\Billing\Providers;

use App\Models\Charge;
use App\Models\Payment;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Wave Checkout API (https://docs.wave.com/checkout).
 *  - POST /v1/checkout/sessions → wave_launch_url (le payeur valide dans son appli Wave)
 *  - webhook signé : en-tête Wave-Signature "t=<timestamp>,v1=<hmac>" ;
 *    HMAC-SHA256(secret, timestamp + corps brut).
 */
class WaveProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'wave';
    }

    public function createCheckout(Payment $payment, Charge $charge, ?string $payerPhone = null): array
    {
        $key = config('services.wave.api_key');
        if (! $key) {
            throw new RuntimeException('Paiement Wave non configuré (WAVE_API_KEY manquant).');
        }

        $body = [
            'amount'           => (string) $charge->amount,
            'currency'         => $charge->currency ?: 'XOF',
            'success_url'      => config('billing.return_urls.success') . '?ref=' . $charge->reference,
            'error_url'        => config('billing.return_urls.error') . '?ref=' . $charge->reference,
            'client_reference' => $payment->uuid,
        ];
        if ($phone = SmsService::normalizeSenegalPhone($payerPhone)) {
            $body['restrict_payer_mobile'] = $phone; // seul ce compte Wave peut payer
        }

        $response = Http::timeout(20)->withToken($key)->acceptJson()
            ->post(rtrim(config('services.wave.base_url'), '/') . '/v1/checkout/sessions', $body);

        if (! $response->successful() || ! $response->json('wave_launch_url')) {
            Log::error('Wave : création de session refusée.', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);
            throw new RuntimeException('Le paiement Wave est momentanément indisponible.');
        }

        return ['reference' => (string) $response->json('id'), 'url' => (string) $response->json('wave_launch_url')];
    }

    public function parseWebhook(Request $request): ?array
    {
        $secret = config('services.wave.webhook_secret');
        $header = (string) $request->header('Wave-Signature');
        $raw    = $request->getContent();

        if (! $secret || ! $header || ! $this->signatureIsValid($header, $raw, $secret)) {
            return null;
        }

        $event = json_decode($raw, true) ?: [];
        $data  = $event['data'] ?? [];

        $status = match (true) {
            ($data['payment_status'] ?? null) === 'succeeded'           => Payment::STATUS_SUCCEEDED,
            ($event['type'] ?? null) === 'checkout.session.payment_failed',
            ($data['payment_status'] ?? null) === 'cancelled'           => Payment::STATUS_FAILED,
            ($data['checkout_status'] ?? null) === 'expired'            => Payment::STATUS_EXPIRED,
            default                                                     => 'ignored',
        };

        return [
            'event'            => $event['type'] ?? null,
            'reference'        => $data['id'] ?? null,
            'client_reference' => $data['client_reference'] ?? null,
            'status'           => $status,
            'transaction_id'   => $data['transaction_id'] ?? null,
            'amount'           => isset($data['amount']) ? (int) $data['amount'] : null,
        ];
    }

    private function signatureIsValid(string $header, string $raw, string $secret): bool
    {
        $timestamp  = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1' && $v) {
                $signatures[] = $v;
            }
        }
        if (! $timestamp || ! $signatures) {
            return false;
        }
        // Rejeu : notification de plus de 10 minutes refusée.
        if (abs(time() - (int) $timestamp) > 600) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $raw, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
