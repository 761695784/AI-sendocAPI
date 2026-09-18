<?php

namespace App\Mail;

use App\Models\Document;
use App\Models\Commentaire;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewCommentNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $document;
    public $commentaire;
    public $auteur;

    public function __construct(Document $document, Commentaire $commentaire, $auteur)
    {
        $this->document = $document;
        $this->commentaire = $commentaire;
        $this->auteur = $auteur;
    }

 public function build()
    {
        $documentUrl = url('https://sendoctrack.netlify.app/document/' . $this->document->uuid); // Générer l'URL de la publication

        return $this->markdown('emails.document.new_comment_notification')
            ->subject('Nouveau commentaire sur votre publication')
            ->with([
                'document' => $this->document,
                'commentaire' => $this->commentaire,
                'auteur' => $this->auteur,
                'documentUrl' => $documentUrl, // Passer l'URL à la vue
            ]);
    }
}
