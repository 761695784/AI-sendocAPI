<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsService
{
    public function sendSMS(string $phoneNumber, string $message): void
    {
        Log::info('Numéro de téléphone avant nettoyage : ' . $phoneNumber);

        // Nettoyer le numéro de téléphone
        $phoneNumber = str_replace('+221', '', $phoneNumber); // Supprime '+221' du numéro
        $phoneNumber = '221' . $phoneNumber; // Ajoute l'indicatif Sénégal

        Log::info('Numéro de téléphone après nettoyage : ' . $phoneNumber);

        if (strlen($phoneNumber) < 12) {
            Log::error('Numéro de téléphone invalide : ' . $phoneNumber);
            return;
        }

        $clientId = config('services.orange.client_id');
        $clientSecret = config('services.orange.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            Log::error('Client ID ou Client Secret non défini.');
            return;
        }

        try {
            $accessToken = $this->getAccessToken($clientId, $clientSecret);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'obtention du token d\'accès : ' . $e->getMessage());
            return;
        }

        $senderAddress = config('services.orange.sender_address');
        if (strpos($senderAddress, 'tel:') !== 0) {
            $senderAddress = 'tel:' . $senderAddress;
        }

        $url = 'https://api.orange.com/smsmessaging/v1/outbound/' . urlencode($senderAddress) . '/requests';

        $data = [
            'outboundSMSMessageRequest' => [
                'address' => 'tel:+' . $phoneNumber,
                'outboundSMSTextMessage' => [
                    'message' => $message,
                ],
                'senderAddress' => $senderAddress,
                'senderName' => "DocTrack",
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Log::error('Erreur lors de l\'envoi du SMS : ' . $error);
            return;
        }

        $responseData = json_decode($response, true);
        if (isset($responseData['outboundSMSMessageRequest'])) {
            Log::info('SMS envoyé avec succès à ' . $phoneNumber);
        } else {
            Log::error('Erreur lors de l\'envoi du SMS : ' . $response);
        }
    }

    protected function getAccessToken(string $clientId, string $clientSecret): ?string
    {
        $url = 'https://api.orange.com/oauth/v3/token';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        Log::info('Réponse de l\'API Orange :', [
            'http_code' => $httpCode,
            'response' => $response,
            'error' => $error,
        ]);

        if ($response === false) {
            throw new \Exception('Erreur cURL : ' . $error);
        }

        $data = json_decode($response, true);
        if (isset($data['access_token'])) {
            return $data['access_token'];
        }

        throw new \Exception('Impossible d\'obtenir le jeton d\'accès');
    }
}
