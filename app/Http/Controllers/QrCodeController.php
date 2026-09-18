<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\FoundDocumentNotification;
use Illuminate\Support\Facades\Validator;
use App\Services\SmsService;

class QrCodeController extends Controller
{
    protected $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    public function handleFoundQr(Request $request)
    {
        // Vérifier les règles de validation
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'finder_phone' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Veuillez fournir des données valides.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Récupérer les données envoyées par le frontend
        $token = $request->input('token');
        $finderPhone = $request->input('finder_phone');
        // Récupérer le token du QR code et verfier sa validité
        $user = User::where('qr_code_token', $token)
            ->where('qr_code_expires_at', '>', now())
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'QR code invalide ou expiré.'
            ], 404);
        }

           // Envoie du mail de notification
            try {
                Mail::to($user->email)->send(new FoundDocumentNotification($finderPhone, $user));
                Log::info('Email envoyé avec succes à ' . $user->email);
            } catch (\Exception $e) {
                Log::error('Failed to send email: ' . $e->getMessage());
            }

        // Envoyer un SMS
        if (!$this->smsService->sendSms($user->phone, "Votre document a été trouvé. Contactez le trouveur au $finderPhone.")) {
            Log::error('Échec de l’envoi du SMS au numéro '. $user->phone);
            // Tu peux choisir de retourner une erreur ou de continuer
            return response()->json(['message' => 'SMS non envoyé']);
        }


        return response()->json(['message' => 'Notifications envoyées avec succès']);
    }


     // Méthode pour envoyer un SMS via l'API Orange
    //   protected function sendSMS($phoneNumber, $message)
    //  {
    //          // Log du numéro de téléphone avant nettoyage
    //          Log::info('Numéro de téléphone avant nettoyage : ' . $phoneNumber);

    //          // Nettoyer le numéro de téléphone
    //          $phoneNumber = str_replace('+221', '', $phoneNumber); // Supprime '+221' du numéro
    //          $phoneNumber = '221' . $phoneNumber; // Ajoute l'indicatif Sénégal

    //          // Log du numéro de téléphone après nettoyage
    //          Log::info('Numéro de téléphone après nettoyage : ' . $phoneNumber);

    //          // Vérifier la longueur du numéro
    //          if (strlen($phoneNumber) < 12) { // 221 + 9 chiffres = 12 caractères
    //              Log::error('Numéro de téléphone invalide : ' . $phoneNumber);
    //              return;
    //          }

    //          // Récupérer les identifiants depuis la configuration
    //          $clientId = config('services.orange.client_id');
    //          $clientSecret = config('services.orange.client_secret');

    //          // Vérifier que les identifiants sont bien définis
    //          if (empty($clientId) || empty($clientSecret)) {
    //              Log::error('Client ID ou Client Secret non défini.');
    //              return;
    //          }

    //          // Log des identifiants
    //          Log::info('Client ID : ' . $clientId);
    //          Log::info('Client Secret : ' . $clientSecret);

    //          // Obtenir le token d'accès
    //          $accessToken = $this->getAccessToken($clientId, $clientSecret);

    //          if (!$accessToken) {
    //              Log::error('Erreur : Impossible de récupérer le token d\'accès.');
    //              return;
    //          }

    //          // Envoyer le SMS
    //          $senderAddress = config('services.orange.sender_address');

    //          // Vérifier que le senderAddress commence par 'tel:'
    //          if (strpos($senderAddress, 'tel:') !== 0) {
    //              $senderAddress = 'tel:' . $senderAddress; // Ajouter le préfixe si absent
    //          }

    //          $url = 'https://api.orange.com/smsmessaging/v1/outbound/' . urlencode($senderAddress) . '/requests';

    //          $data = [
    //              'outboundSMSMessageRequest' => [
    //                  'address' => 'tel:+' . $phoneNumber,  // Format international complet
    //                  'outboundSMSTextMessage' => [
    //                      'message' => $message,
    //                  ],
    //                  'senderAddress' => $senderAddress, // Utiliser le senderAddress formaté
    //                  // 'senderName' => "SMS 183786",
    //                  'senderName' => "DocTrack",
    //              ]
    //          ];

    //          $ch = curl_init();
    //          curl_setopt($ch, CURLOPT_URL, $url);
    //          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //          curl_setopt($ch, CURLOPT_POST, true);
    //          curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //              'Authorization: Bearer ' . $accessToken,
    //              'Content-Type: application/json',
    //              'Accept: application/json',
    //          ]);
    //          curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    //          $response = curl_exec($ch);
    //          $error = curl_error($ch);
    //          curl_close($ch);

    //          if ($response === false) {
    //              Log::error('Erreur lors de l\'envoi du SMS : ' . $error);
    //              return;
    //          }

    //          $responseData = json_decode($response, true);
    //          if (isset($responseData['outboundSMSMessageRequest'])) {
    //              Log::info('SMS envoyé avec succès à ' . $phoneNumber);
    //          } else {
    //              Log::error('Erreur lors de l\'envoi du SMS : ' . $response);
    //          }
    //  }

    //   // Méthode pour obtenir un jeton d'accès
    //  protected function getAccessToken($clientId, $clientSecret)
    //  {
    //          $url = 'https://api.orange.com/oauth/v3/token';

    //          // Log des identifiants et de l'en-tête d'autorisation
    //          Log::info('Tentative d\'obtention du token avec les identifiants :', [
    //              'clientId' => $clientId,
    //              'clientSecret' => $clientSecret,
    //              'authorizationHeader' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
    //          ]);

    //          $ch = curl_init();
    //          curl_setopt($ch, CURLOPT_URL, $url);
    //          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //          curl_setopt($ch, CURLOPT_POST, true);
    //          curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //              'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
    //              'Content-Type: application/x-www-form-urlencoded',
    //              'Accept: application/json',
    //          ]);
    //          curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');

    //          $response = curl_exec($ch);
    //          $error = curl_error($ch);
    //          $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Récupère le code HTTP
    //          curl_close($ch);

    //          // Log de la réponse
    //          Log::info('Réponse de l\'API Orange :', [
    //              'http_code' => $httpCode,
    //              'response' => $response,
    //              'error' => $error,
    //          ]);

    //          if ($response === false) {
    //              Log::error('Erreur lors de l\'obtention du jeton d\'accès : ' . $error);
    //              throw new \Exception('Erreur cURL : ' . $error);
    //          }

    //          $data = json_decode($response, true);
    //          if (isset($data['access_token'])) {
    //              return $data['access_token'];
    //          }

    //          Log::error('Erreur lors de l\'obtention du jeton d\'accès : ' . $response);
    //          throw new \Exception('Impossible d\'obtenir le jeton d\'accès');
    //  }


}
