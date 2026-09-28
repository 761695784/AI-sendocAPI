<?php

namespace App\Services\Billing\Fulfillers;

use App\Models\Charge;
use App\Models\DeclarationDePerte;
use App\Services\Declarations\OfficialCertificateService;
use App\Services\Notifications\Notifier;

/** Déclaration au guichet réglée → certificat officiel + SMS de reçu à la victime. */
class CounterDeclarationFulfiller implements ChargeFulfiller
{
    public function __construct(private OfficialCertificateService $certificates, private Notifier $notifier) {}

    public function fulfill(Charge $charge): void
    {
        $declaration = $charge->subject;
        if (! $declaration instanceof DeclarationDePerte || $declaration->certificat) {
            return;
        }

        $structure = $charge->organization ?? $declaration->organization;
        $agent     = $charge->creator ?? $declaration->recordedBy;
        $citizen   = $declaration->citizenProfile;

        $certificate = $this->certificates->issue($declaration, $agent, $structure);

        $this->notifier->sms('counter_declaration.registered', $citizen?->phone(), [
            'reference'  => $declaration->reference,
            'structure'  => $structure->name,
            'verify_url' => $certificate->verificationUrl(),
        ], ['recipient' => $citizen, 'subject' => $declaration, 'organization_id' => $structure->id,
            'dedupe' => 'counter_declaration.registered:' . $declaration->id]);
    }
}
