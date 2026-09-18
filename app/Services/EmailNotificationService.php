<?php

namespace App\Services;

use App\Mail\DocumentPublishedNotification;
use App\Models\EmailLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailNotificationService
{
    /**
     * Envoie un email au déclarant et logue l'envoi en base.
     *
     * @param  object $document   Le document créé
     * @param  object $declarant  L'utilisateur ayant fait la déclaration
     * @param  string $phone      Numéro de téléphone du publicateur
     * @param  string $documentUrl Lien vers le document
     * @return void
     */
    public function notifyDeclarant($document, $declarant, string $phone, string $documentUrl): void
    {
        try {
            // Envoi de l'email
            Mail::to($declarant->email)
                ->send(new DocumentPublishedNotification($document, $phone, $documentUrl));

            // Log de l'email en base
            EmailLog::create([
                'from'               => config('mail.from.address'),
                'to'                 => $declarant->email,
                'subject'            => 'Correspondance à votre déclaration de perte',
                'body'               => 'Le document publié correspond aux informations : ' .
                                         $document->OwnerFirstName . ' ' . $document->OwnerLastName .
                                         ' avec le numéro du publicateur : ' . $phone,
                'publisher_user_id'  => $document->user->id,
                'requester_user_id'  => $declarant->id,
                'document_id'        => $document->uuid,
                'declarant_user_id'  => $declarant->id,
            ]);

            Log::info('Email envoyé avec succès à ' . $declarant->email);

        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de la notification email : ' . $e->getMessage(), [
                'publisher_user_id' => $document->user->id ?? null,
                'requester_user_id' => $declarant->id ?? null,
                'document_id'       => $document->uuid ?? null,
                'declarant_user_id' => $declarant->id ?? null,
            ]);
        }
    }
}
