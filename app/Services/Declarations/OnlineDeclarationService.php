<?php

namespace App\Services\Declarations;

use App\Http\Controllers\DocumentController;
use App\Jobs\MatchDeclarationJob;
use App\Jobs\NotifyDeclarantJob;
use App\Models\CertificatDePerte;
use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\User;
use App\Services\CertificatDePerteService;
use Illuminate\Support\Facades\Cache;

/**
 * Déclaration de perte faite en ligne par le citoyen lui-même (formulaire V1
 * ou assistant conversationnel) : même traitement dans les deux cas.
 */
class OnlineDeclarationService
{
    public function __construct(
        private CertificatDePerteService $certificats,
        private CitizenProfileService $citizens,
    ) {}

    /**
     * @param  array{Title:string, FirstNameInDoc:string, LastNameInDoc:string, DocIdentification?:?string,
     *               document_type_id:int, lost_place?:?string, lost_at?:?string}  $data
     * @param  array  $logContext  propriétés ajoutées au journal (ex. ['via' => 'assistant'])
     * @return array{0: DeclarationDePerte, 1: CertificatDePerte}
     */
    public function create(User $user, array $data, array $logContext = []): array
    {
        $declaration = DeclarationDePerte::create([
            'Title'              => $data['Title'],
            'FirstNameInDoc'     => $data['FirstNameInDoc'],
            'LastNameInDoc'      => $data['LastNameInDoc'],
            'DocIdentification'  => $data['DocIdentification'] ?? null,
            'document_type_id'   => $data['document_type_id'],
            'lost_place'         => $data['lost_place'] ?? null,
            'lost_at'            => $data['lost_at'] ?? null,
            'user_id'            => $user->id,
            'channel'            => DeclarationDePerte::CHANNEL_ONLINE,
            'citizen_profile_id' => $this->citizens->forAccount($user)->id,
        ]);

        activity()
            ->causedBy($user)
            ->performedOn($declaration)
            ->withProperties(array_merge([
                'Title'            => $declaration->Title,
                'FirstNameInDoc'   => $declaration->FirstNameInDoc,
                'LastNameInDoc'    => $declaration->LastNameInDoc,
                'document_type_id' => $declaration->document_type_id,
            ], $logContext))
            ->log('Déclaration de perte créée');

        // Pièce déclarée (modèle V2) → visible par le moteur de correspondance.
        $declaration->ensureLegacyItem();

        $certificat = $this->certificats->genererCertificat($declaration);

        Cache::forget('statistics_month_' . now()->format('Y_m'));

        // Correspondances V1 (mêmes nom et prénom, publiés par quelqu'un d'autre).
        Document::where('document_type_id', $data['document_type_id'])
            ->whereRaw('LOWER(OwnerFirstName) = LOWER(?)', [$data['FirstNameInDoc']])
            ->whereRaw('LOWER(OwnerLastName) = LOWER(?)', [$data['LastNameInDoc']])
            ->where('statut', 'non récupéré')
            ->where('user_id', '!=', $user->id)
            ->get()
            ->each(fn (Document $document) => dispatch(new NotifyDeclarantJob(
                $document,
                $user,
                DocumentController::frontendDocumentUrl($document),
                $declaration->id
            )));

        // Moteur V2 : documents trouvés publiés ou déposés dans une structure.
        MatchDeclarationJob::dispatch($declaration->id);

        return [$declaration, $certificat];
    }
}
