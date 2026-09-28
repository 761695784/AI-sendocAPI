<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Correspondance présentée à l'agent chargé de la revue : les deux côtés
 * côte à côte, et l'explication du score. Jamais le téléphone du déclarant.
 */
class MatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->item;
        $doc  = $this->document;
        $decl = $this->declaration;

        return [
            'uuid'           => $this->uuid,
            'status'         => $this->status,
            'score'          => $this->score,
            'number_match'   => $this->number_match,
            'engine'         => $this->engine . ' ' . $this->engine_version,
            'score_details'  => $this->score_details,
            'organization'   => $this->organization ? ['uuid' => $this->organization->uuid, 'name' => $this->organization->name] : null,
            'lost_item'      => $item ? [
                'declaration_reference' => $decl?->reference,
                'declaration_channel'   => $decl?->channel,
                'declared_at'           => $decl?->created_at,
                'declared_by_structure' => $decl?->organization?->name,
                'document_type'         => $item->documentType?->TypeName,
                'first_name'            => $item->first_name,
                'last_name'             => $item->last_name,
                'birth_date'            => $item->birth_date_in_doc?->toDateString(),
                'doc_number_last4'      => $item->doc_number_last4,
                'lost_at'               => $decl?->lost_at?->toDateString(),
                'lost_place'            => $decl?->lost_place,
            ] : null,
            'found_document' => $doc ? [
                'reference'        => $doc->reference,
                'uuid'             => $doc->uuid,
                'document_type'    => $doc->documentType?->TypeName,
                'first_name'       => $doc->OwnerFirstName,
                'last_name'        => $doc->OwnerLastName,
                'birth_date'       => $doc->birth_date_in_doc?->toDateString(),
                'doc_number_last4' => $doc->doc_number_last4,
                'found_at'         => ($doc->found_at ?? $doc->created_at)?->toDateString(),
                'found_place'      => $doc->found_place ?? $doc->Location,
                'custody'          => $doc->custodyOrganization?->name,
                'lifecycle_status' => $doc->lifecycle_status,
            ] : null,
            'reviewed_by'    => $this->reviewer ? trim($this->reviewer->FirstName . ' ' . $this->reviewer->LastName) : null,
            'reviewed_at'    => $this->reviewed_at,
            'review_note'    => $this->review_note,
            'created_at'     => $this->created_at,
        ];
    }
}
