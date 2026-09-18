<?php
namespace App\Http\Controllers;

use App\Http\Requests\StoreDeclarationDePerteRequest;
use App\Mail\DocumentPublishedNotification;
use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Services\CertificatDePerteService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Traits\HasRoles;

class DeclarationDePerteController extends Controller
{
    use HasRoles;

    // ══════════════════════════════════════════════════════════════
    // STORE — Créer une déclaration
    // ══════════════════════════════════════════════════════════════
    public function store(StoreDeclarationDePerteRequest $request, CertificatDePerteService $certificatService, SmsService $smsService)
    {
        // Vérifier si l'utilisateur est authentifié
        $user = Auth::user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401);
        }
        // Effectuer les validations
        $validatedData = $request->validated();
        $declaration   = DeclarationDePerte::create([
            'Title'             => $validatedData['Title'],
            'FirstNameInDoc'    => $validatedData['FirstNameInDoc'],
            'LastNameInDoc'     => $validatedData['LastNameInDoc'],
            'DocIdentification' => $validatedData['DocIdentification'] ?? null,
            'document_type_id'  => $validatedData['document_type_id'],
            'user_id'           => $user->id,
        ]);

        // ── Log d'activité ──
        /** @var \App\Models\User $user */
        $user = Auth::user();
        activity()
            ->causedBy($user)
            ->performedOn($declaration)
            ->withProperties([
                'Title'            => $declaration->Title,
                'FirstNameInDoc'   => $declaration->FirstNameInDoc,
                'LastNameInDoc'    => $declaration->LastNameInDoc,
                'document_type_id' => $declaration->document_type_id,
            ])
            ->log('Déclaration de perte créée');

        // Générer automatiquement le certificat
        $certificat = $certificatService->genererCertificat($declaration);

        // Invalide le cache des stats
        Cache::forget('statistics_month_' . now()->format('Y_m'));
        // Création d'une notification
        // Notification::create([
        //     'message' => 'Nouvelle déclaration de perte créée : ' . $declaration->Title . ' ' . $declaration->FirstNameInDoc . ' ' . $declaration->LastNameInDoc,
        //     'is_read' => false,
        // ]);
        // Recherche des documents correspondants
        $matchingDocuments = Document::where('document_type_id', $validatedData['document_type_id'])
            ->whereRaw('LOWER(OwnerFirstName) = LOWER(?)', [$validatedData['FirstNameInDoc']])
            ->whereRaw('LOWER(OwnerLastName) = LOWER(?)', [$validatedData['LastNameInDoc']])
            ->with('user')
            ->get();

        foreach ($matchingDocuments as $document) {
            try {
                // Envoi email
                $this->sendNotificationEmail($user, $document);

                // Envoi SMS via le service SmsService
                $phoneNumber = $user->Phone;
                $documentUrl = 'https://sendoctrack.netlify.app/document/' . $document->uuid;
                $message     = 'Un document correspondant à votre déclaration de perte a été trouvé : ' .
                $document->OwnerFirstName . ' ' . $document->OwnerLastName .
                    '. Consultez-le ici : ' . $documentUrl;

                $smsService->sendSMS($phoneNumber, $message);
            } catch (\Exception $e) {
                Log::error('Erreur lors de l\'envoi de la notification ou du SMS : ' . $e->getMessage());
            }
        }
        return response()->json([
            'success' => true,
            'message' => 'Déclaration de perte créée avec succès.',
            'data'    => [
                'declaration' => $declaration,
                'certificat'  => $certificat,
                                                                                         // 'pdf_url' => asset('storage/' . $certificat->pdf_path)
                'pdf_url'     => url('/api/certificats/' . $certificat->uuid . '/voir'), // Lien pour voir le PDF via l'API
            ],
        ], 201);
    }

    // Fonction pour l'envoi de notification par email
    private function sendNotificationEmail($user, $document)
    {
        $Phone = $document->user->Phone;
        // $documentUrl = 'https://sendoctrack.netlify.app/document/' . $document->id;
        $documentUrl = 'https://sendoctrack.netlify.app/document/' . $document->uuid;

        try {
            // Envoi de l'email
            Mail::to($user->email)->send(new DocumentPublishedNotification($document, $Phone, $documentUrl));

            // Enregistrer un log d'email dans la table email_logs
            \App\Models\EmailLog::create([
                'from'              => config('mail.from.address'),
                'to'                => $user->email,
                'subject'           => 'Correspondance à votre déclaration de perte',
                'body'              => 'Le document publié correspond aux informations : ' .
                $document->OwnerFirstName . ' ' . $document->OwnerLastName .
                ' avec le numéro du publicateur : ' . $Phone,
                'publisher_user_id' => $document->user->id,
                'requester_user_id' => $user->id,
                'document_id'       => $document->uuid,
                'declarant_user_id' => $user->id,
            ]);

            Log::info('Email log enregistré avec succès pour l\'utilisateur ' . $user->email);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi ou de l\'enregistrement de l\'email log : ' . $e->getMessage(), [
                'publisher_user_id' => $document->user->id,
                'requester_user_id' => $user->id,
                'document_id'       => $document->uuid,
                'declarant_user_id' => $user->id,
            ]);
        }
    }

    // // Méthode pour envoyer un SMS via l'API Orange
    // protected function sendSMS($phoneNumber, $message)
    // {
    //     // Log du numéro de téléphone avant nettoyage
    //     Log::info('Numéro de téléphone avant nettoyage : ' . $phoneNumber);

    //     // Nettoyer le numéro de téléphone
    //     $phoneNumber = str_replace('+221', '', $phoneNumber); // Supprime '+221' du numéro
    //     $phoneNumber = '221' . $phoneNumber; // Ajoute l'indicatif Sénégal

    //     // Log du numéro de téléphone après nettoyage
    //     Log::info('Numéro de téléphone après nettoyage : ' . $phoneNumber);

    //     // Vérifier la longueur du numéro
    //     if (strlen($phoneNumber) < 12) { // 221 + 9 chiffres = 12 caractères
    //         Log::error('Numéro de téléphone invalide : ' . $phoneNumber);
    //         return;
    //     }

    //     // Récupérer les identifiants depuis la configuration
    //     $clientId = config('services.orange.client_id');
    //     $clientSecret = config('services.orange.client_secret');

    //     // Vérifier que les identifiants sont bien définis
    //     if (empty($clientId) || empty($clientSecret)) {
    //         Log::error('Client ID ou Client Secret non défini.');
    //         return;
    //     }

    //     // Log des identifiants
    //     Log::info('Client ID : ' . $clientId);
    //     Log::info('Client Secret : ' . $clientSecret);

    //     // Obtenir le token d'accès
    //     $accessToken = $this->getAccessToken($clientId, $clientSecret);

    //     if (!$accessToken) {
    //         Log::error('Erreur : Impossible de récupérer le token d\'accès.');
    //         return;
    //     }

    //     // Envoyer le SMS
    //     $senderAddress = config('services.orange.sender_address');

    //     // Vérifier que le senderAddress commence par 'tel:'
    //     if (strpos($senderAddress, 'tel:') !== 0) {
    //         $senderAddress = 'tel:' . $senderAddress; // Ajouter le préfixe si absent
    //     }

    //     $url = 'https://api.orange.com/smsmessaging/v1/outbound/' . urlencode($senderAddress) . '/requests';

    //     $data = [
    //         'outboundSMSMessageRequest' => [
    //             'address' => 'tel:+' . $phoneNumber,  // Format international complet
    //             'outboundSMSTextMessage' => [
    //                 'message' => $message,
    //             ],
    //             'senderAddress' => $senderAddress, // Utiliser le senderAddress formaté
    //             // 'senderName' => "SMS 183786",
    //                 'senderName' => "DocTrack",
    //         ]
    //     ];

    //     $ch = curl_init();
    //     curl_setopt($ch, CURLOPT_URL, $url);
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //     curl_setopt($ch, CURLOPT_POST, true);
    //     curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //         'Authorization: Bearer ' . $accessToken,
    //         'Content-Type: application/json',
    //         'Accept: application/json',
    //     ]);
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    //     $response = curl_exec($ch);
    //     $error = curl_error($ch);
    //     curl_close($ch);

    //     if ($response === false) {
    //         Log::error('Erreur lors de l\'envoi du SMS : ' . $error);
    //         return;
    //     }

    //     $responseData = json_decode($response, true);
    //     if (isset($responseData['outboundSMSMessageRequest'])) {
    //         Log::info('SMS envoyé avec succès à ' . $phoneNumber);
    //     } else {
    //         Log::error('Erreur lors de l\'envoi du SMS : ' . $response);
    //     }
    // }

    // // Méthode pour obtenir un jeton d'accès
    // protected function getAccessToken($clientId, $clientSecret)
    // {
    //     $url = 'https://api.orange.com/oauth/v3/token';

    //     // Log des identifiants et de l'en-tête d'autorisation
    //     Log::info('Tentative d\'obtention du token avec les identifiants :', [
    //         'clientId' => $clientId,
    //         'clientSecret' => $clientSecret,
    //         'authorizationHeader' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
    //     ]);

    //     $ch = curl_init();
    //     curl_setopt($ch, CURLOPT_URL, $url);
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //     curl_setopt($ch, CURLOPT_POST, true);
    //     curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //         'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
    //         'Content-Type: application/x-www-form-urlencoded',
    //         'Accept: application/json',
    //     ]);
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');

    //     $response = curl_exec($ch);
    //     $error = curl_error($ch);
    //     $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Récupère le code HTTP
    //     curl_close($ch);

    //     // Log de la réponse
    //     Log::info('Réponse de l\'API Orange :', [
    //         'http_code' => $httpCode,
    //         'response' => $response,
    //         'error' => $error,
    //     ]);

    //     if ($response === false) {
    //         Log::error('Erreur lors de l\'obtention du jeton d\'accès : ' . $error);
    //         throw new \Exception('Erreur cURL : ' . $error);
    //     }

    //     $data = json_decode($response, true);
    //     if (isset($data['access_token'])) {
    //         return $data['access_token'];
    //     }

    //     Log::error('Erreur lors de l\'obtention du jeton d\'accès : ' . $response);
    //     throw new \Exception('Impossible d\'obtenir le jeton d\'accès');
    // }

    // ══════════════════════════════════════════════════════════════
    // INDEX — Liste des déclarations
    // ══════════════════════════════════════════════════════════════
    public function index()
    {
        $user = Auth::user();

        // Si l'utilisateur n'est pas authentifié, retourner une erreur
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401); // Code 401 Unauthorized
        }

        // Si l'utilisateur est un administrateur, récupérer toutes les déclarations (y compris celles supprimées)
        if ($user->hasRole('Admin')) {
            $declarations = DeclarationDePerte::withTrashed()
                ->with(['user', 'documentType'])
                ->latest()
                ->paginate(20);
        } else {
            // Si l'utilisateur est un simple utilisateur, ne récupérer que ses propres déclarations (non supprimées)
            $declarations = DeclarationDePerte::with(['user', 'documentType'])
                ->where('user_id', $user->id)
                ->latest()
                ->paginate(20);
        }

        return response()->json([
            'success' => true,
            'data'    => $declarations->items(), // ← plus de double imbrication data.data
        ]);
    }

    // Méthode pour afficher toutes les déclarations supprimées du user connecté
    public function trashedDeclarations()
    {
        // verifier si le user est connecte
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401); // Code 401 Unauthorized
        }

        // Récupérer les déclarations supprimées de l'utilisateur connecté
        $declarations = DeclarationDePerte::onlyTrashed()
            ->where('user_id', $user->id)
            ->with('user')
            ->get();

        if ($declarations->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Aucune déclaration supprimée trouvée.',
                'data'    => [],
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $declarations,
        ]);
    }

    // Méthode pour restaurer une déclaration supprimée
    public function restoreTrashedDeclaration($uuid)
    {
        // Vérifier si l'utilisateur est connecté
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401);
        }

        // Vérifier si la déclaration existe et si c'est une déclaration supprimée de l'utilisateur connecté
        $declaration = DeclarationDePerte::onlyTrashed()->where('uuid', $uuid)->firstOrFail();

        if ($declaration->user_id === $user->id) {
            $declaration->restore();
            return response()->json([
                'success' => true,
                'message' => 'Déclaration restaurée avec succès.',
            ]);
        }

        // ── Log d'activité ──
        /** @var \App\Models\User $user */
        $user = Auth::user();
        activity()
            ->causedBy($user)
            ->performedOn($declaration)
            ->withProperties(['uuid' => $declaration->uuid])
            ->log('Déclaration restaurée');

        return response()->json([
            'success' => false,
            'message' => 'Accès refusé. Vous ne pouvez restaurer que vos propres déclarations.',
        ], 403);
    }

    // Fonction pour obtenir les déclarations faites par l'utilisateur connecté
    public function getUserDeclarations()
    {
        // Vérifier si l'utilisateur est authentifié
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour voir vos déclarations.',
            ], 401);
        }

        // Récupérer les déclarations faites par l'utilisateur connecté avec les informations de l'utilisateur et du type de document
        $declarations = DeclarationDePerte::where('user_id', $user->id)
            ->with(['user', 'documentType', 'certificat']) // Charger les relations user et documentType
            ->get();
        // dd($declarations->first()->certificat);

        return response()->json([
            'success' => true,
            'data'    => $declarations,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // SHOW — Déclaration spécifique
    // ══════════════════════════════════════════════════════════════
    public function show($uuid)
    {
        // Vérifier si l'utilisateur est authentifié
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401);
        }

        // Charger les informations de l'utilisateur et le type de document
        $declaration = DeclarationDePerte::with(['user', 'documentType'])->where('uuid', $uuid)->firstOrFail();

        // Si l'utilisateur est un simple utilisateur, vérifier qu'il est propriétaire de la déclaration ou bien est ce l'administrator
        if ($user->hasRole('Admin') || $declaration->user_id === $user->id) {
            return response()->json([
                'success' => true,
                'data'    => $declaration,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Accès refusé. Vous ne pouvez voir que vos propres déclarations.',
        ], 403);
    }

    // ══════════════════════════════════════════════════════════════
    // DESTROY — Supprimer une déclaration
    // ══════════════════════════════════════════════════════════════
    public function destroy($uuid)
    {
        // Vérifier si l'utilisateur est authentifié
        $user = Auth::user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour effectuer cette action.',
            ], 401);
        }
        // Récupérer la declaration par son uuid
        $declaration = DeclarationDePerte::where('uuid', $uuid)->firstOrFail();

        // Vérifier si l'utilisateur est le propriétaire de la déclaration ou si c'est un administrateur
        if ($user->hasRole('Admin') || $declaration->user_id === $user->id) {

            // ── Log d'activité ──
            /** @var \App\Models\User $user */
            activity()
                ->causedBy($user)
                ->performedOn($declaration)
                ->withProperties([
                    'uuid'       => $declaration->uuid,
                    'deleted_by' => $user->id,
                ])
                ->log('Déclaration supprimée');

            $declaration->delete();

            Cache::forget('statistics_month_' . now()->format('Y_m'));

            return response()->json([
                'success' => true,
                'message' => 'Déclaration de perte supprimée avec succès.',
            ]);
        }

        // retourner la reponse  de l'api
        return response()->json([
            'success' => false,
            'message' => 'Accès refusé. Vous ne pouvez supprimer que vos propres déclarations.',
        ], 403);
    }
}
