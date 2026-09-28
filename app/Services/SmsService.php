<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoi de SMS via l'API Orange (SMS Messaging).
 *
 * - Ne lève jamais d'exception : renvoie true/false, l'appelant décide.
 * - Normalise les numéros sénégalais au format E.164 (+221XXXXXXXXX).
 * - Met en cache le jeton OAuth (évite une authentification par SMS).
 * - Ne journalise ni identifiants, ni jeton, ni numéro complet.
 */
class SmsService
{
    private const TOKEN_URL = 'https://api.orange.com/oauth/v3/token';
    private const SMS_URL   = 'https://api.orange.com/smsmessaging/v1/outbound/%s/requests';

    public function sendSMS(?string $phoneNumber, string $message): bool
    {
        $recipient = self::normalizeSenegalPhone($phoneNumber);

        if (! $recipient) {
            Log::warning('SMS non envoyé : numéro invalide ou absent.', ['phone' => self::maskPhone($phoneNumber)]);
            return false;
        }

        $clientId     = config('services.orange.client_id');
        $clientSecret = config('services.orange.client_secret');
        $sender       = config('services.orange.sender_address');

        if (empty($clientId) || empty($clientSecret) || empty($sender)) {
            Log::error('SMS non envoyé : ORANGE_CLIENT_ID, ORANGE_CLIENT_SECRET ou ORANGE_SENDER_ADDRESS manquant dans le .env.');
            return false;
        }

        $accessToken = $this->getAccessToken($clientId, $clientSecret);
        if (! $accessToken) {
            return false;
        }

        // L'adresse d'expéditeur doit être au format "tel:+221XXXXXXXXX".
        $senderAddress = 'tel:+' . ltrim(preg_replace('/^tel:/', '', $sender), '+');

        try {
            $response = Http::timeout(15)
                ->withToken($accessToken)
                ->acceptJson()
                ->post(sprintf(self::SMS_URL, urlencode($senderAddress)), [
                    'outboundSMSMessageRequest' => [
                        'address'                => 'tel:' . $recipient,
                        'senderAddress'          => $senderAddress,
                        'senderName'             => config('services.orange.sender_name', 'DocTrack'),
                        'outboundSMSTextMessage' => ['message' => $message],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('SMS non envoyé : erreur réseau vers Orange.', ['error' => $e->getMessage()]);
            return false;
        }

        if ($response->successful()) {
            Log::info('SMS envoyé.', ['to' => self::maskPhone($recipient)]);
            return true;
        }

        if ($response->status() === 401) {
            Cache::forget('orange_sms_token');
        }

        Log::error('SMS refusé par Orange.', [
            'status' => $response->status(),
            'to'     => self::maskPhone($recipient),
            'body'   => mb_substr($response->body(), 0, 500),
        ]);

        return false;
    }

    /**
     * "77 123 45 67", "0022177…", "221771234567", "+221 77…" → "+221771234567".
     * Renvoie null si le numéro n'est pas un numéro sénégalais valide (9 chiffres).
     */
    public static function normalizeSenegalPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '00221')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '221') && strlen($digits) === 12) {
            $digits = substr($digits, 3);
        }

        // Mobiles 70/71/75/76/77/78 et fixes 33 : 9 chiffres.
        if (! preg_match('/^(7[0-8]|3[0-9])\d{7}$/', $digits)) {
            return null;
        }

        return '+221' . $digits;
    }

    public static function maskPhone(?string $phone): ?string
    {
        if (! $phone) {
            return $phone;
        }

        return str_repeat('*', max(strlen($phone) - 4, 0)) . substr($phone, -4);
    }

    private function getAccessToken(string $clientId, string $clientSecret): ?string
    {
        if ($token = Cache::get('orange_sms_token')) {
            return $token;
        }

        try {
            $response = Http::timeout(15)
                ->withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->acceptJson()
                ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials']);
        } catch (\Throwable $e) {
            Log::error('Jeton Orange : erreur réseau.', ['error' => $e->getMessage()]);
            return null;
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! $token) {
            Log::error('Jeton Orange refusé (vérifier les identifiants et l\'abonnement SMS).', [
                'status' => $response->status(),
            ]);
            return null;
        }

        // Marge de 60 s sur la durée annoncée par Orange.
        $ttl = max((int) $response->json('expires_in', 3600) - 60, 60);
        Cache::put('orange_sms_token', $token, $ttl);

        return $token;
    }
}
