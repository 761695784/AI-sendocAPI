<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Document trouvé vu par un agent de la structure qui en a la garde.
 */
class FoundDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'             => $this->uuid,
            'reference'        => $this->reference,
            'found_channel'    => $this->found_channel,
            'lifecycle_status' => $this->lifecycle_status,
            'document_type'    => ['id' => $this->document_type_id, 'name' => $this->documentType?->TypeName],
            'first_name'       => $this->OwnerFirstName,
            'last_name'        => $this->OwnerLastName,
            'birth_date'       => $this->birth_date_in_doc?->toDateString(),
            'doc_number'       => $this->docNumber() ?? $this->DocIdentification,
            'found_at'         => $this->found_at?->toDateString(),
            'found_place'      => $this->found_place ?? $this->Location,
            'custody'          => $this->custodyOrganization ? [
                'organization' => ['uuid' => $this->custodyOrganization->uuid, 'name' => $this->custodyOrganization->name],
                'since'        => $this->custody_since,
                'deposited_by' => $this->depositedBy ? trim($this->depositedBy->FirstName . ' ' . $this->depositedBy->LastName) : null,
            ] : null,
            'finder'           => $this->whenLoaded('finder', fn () => $this->finder ? (new CitizenProfileResource($this->finder))->resolve() : null),
            'image_url'        => $this->getFirstMedia('document_image')
                ? URL::temporarySignedRoute('documents.image', now()->addMinutes(15), ['uuid' => $this->uuid])
                : null,
            'matches'          => $this->whenLoaded('matches', fn () => $this->matches->map(fn ($m) => [
                'uuid'   => $m->uuid,
                'score'  => $m->score,
                'status' => $m->status,
            ])->values()->all()),
            'created_at'       => $this->created_at,
        ];
    }
}
