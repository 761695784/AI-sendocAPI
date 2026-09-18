<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FoundDocumentNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $finderPhone;
    public $document;
    /**
     * Create a new message instance.
     */
    public function __construct($finderPhone,$document)
    {
        $this->finderPhone = $finderPhone;
        $this->document = $document;
    }

    /**
     * Build the message.
     *
     */
    public function build()
    {
        return $this->subject('Votre document a été trouvé')
                    ->markdown('emails.document.found_document')
                    ->with(['finderPhone' => $this->finderPhone, 'document' => $this->document,]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Document Trouvé',
        );
    }
}
