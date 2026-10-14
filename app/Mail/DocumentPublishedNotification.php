<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DocumentPublishedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $document;
    public $Phone;
    public $documentUrl;

    public $recipientName;

    public function __construct($document, $Phone, $documentUrl, $recipientName = null)
    {
        $this->recipientName = $recipientName;
        $this->document = $document;
        $this->Phone = $Phone;
        $this->documentUrl = $documentUrl;
    }

    public function build()
    {
        // Assurez-vous que $documentUrl correspond au chemin correct du frontend
        $documentUrl = rtrim(config('doctrack.frontend_url'), '/') . '/document/' . $this->document->uuid; // Générer l'URL de la publication

        return $this->subject('Correspondance à votre déclaration de perte')
                    ->markdown('emails.document.published', [
                        'document' => $this->document,
                        'Phone' => $this->Phone,
                        'documentUrl' => $documentUrl,
                        'recipientName' => $this->recipientName,
                    ]);
    }
}
