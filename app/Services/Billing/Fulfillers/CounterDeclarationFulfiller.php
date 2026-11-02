<?php

namespace App\Services\Billing\Fulfillers;

use App\Models\Charge;
use App\Models\DeclarationDePerte;
use App\Services\Declarations\OfficialCertificateService;
use App\Services\Notifications\Notifier;

/**
 * Déclaration au guichet (ou déclaration en ligne certifiée au guichet) réglée
 * → certificat officiel + SMS à la victime.
 */
class CounterDeclarationFulfiller implements ChargeFulfiller
{
    public function __construct(private OfficialCertificateService $certificates, private Notifier $notifier) {}

    public function fulfill(Charge $charge): void
    {
        $declaration = $charge->subject;
        // Un récépissé en ligne n'empêche pas la délivrance : seul un certificat officiel existant la bloque.
        if (! $declaration instanceof DeclarationDePerte || $declaration->officialCertificate) {
            return;
        }

        $structure = $charge->organization ?? $declaration->organization;
        $agent     = $charge->creator ?? $declaration->recordedBy;
        $citizen   = $declaration->citizenProfile;

        $certificate = $this->certificates->issue($declaration, $agent, $structure);

        $template = $declaration->isCounter() ? 'counter_declaration.registered' : 'online_declaration.certified';

        $this->notifier->sms($template, $citizen?->phone(), [
            'reference'  => $declaration->reference,
            'structure'  => $structure->name,
            'verify_url' => $certificate->verificationUrl(),
        ], ['recipient' => $citizen, 'subject' => $declaration, 'organization_id' => $structure->id,
            'dedupe' => $template . ':' . $declaration->id]);
    }
}
