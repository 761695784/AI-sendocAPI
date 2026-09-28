<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Collecte vue par la structure. Le numéro complet du trouveur n'apparaît
 * que sur la fiche (consultation tracée), jamais dans la liste.
 */
class CollectionRequestResource extends JsonResource
{
    public bool $withPhone = false;

    public function toArray(Request $request): array
    {
        $doc   = $this->document;
        $phone = $this->finderPhone();

        return [
            'uuid'              => $this->uuid,
            'status'            => $this->status,
            'priority'          => $this->owner_identified_at !== null,
            'owner_identified_at' => $this->owner_identified_at,
            'organization'      => $this->organization ? ['uuid' => $this->organization->uuid, 'name' => $this->organization->name] : null,
            'assigned_at'       => $this->assigned_at,
            'assignment_reason' => $this->assignment_reason,
            'distance_km'       => $this->distance_km,
            'document'          => $doc ? [
                'uuid'          => $doc->uuid,
                'reference'     => $doc->reference,
                'document_type' => $doc->documentType?->TypeName,
                'first_name'    => $doc->OwnerFirstName,
                'last_name'     => $doc->OwnerLastName,
                'found_place'   => $doc->found_place ?? $doc->Location,
                'latitude'      => $doc->found_latitude,
                'longitude'     => $doc->found_longitude,
                'territory'     => $doc->territory?->name,
                'published_at'  => $doc->created_at,
                'image_url'     => $this->withPhone && $doc->getFirstMedia('document_image')
                    ? URL::temporarySignedRoute('documents.image', now()->addMinutes(15), ['uuid' => $doc->uuid])
                    : null,
            ] : null,
            'finder'            => [
                'name'  => $this->finder_name,
                'phone' => $this->withPhone ? $phone : ($phone ? '••••••' . substr($phone, -3) : null),
            ],
            'contact_attempts'     => $this->contact_attempts,
            'last_contact_at'      => $this->last_contact_at,
            'last_contact_outcome' => $this->last_contact_outcome,
            'method'               => $this->method,
            'scheduled_at'         => $this->scheduled_at,
            'meeting_place'        => $this->meeting_place,
            'pickup_agent'         => $this->pickupAgent ? ['uuid' => $this->pickupAgent->uuid, 'name' => trim($this->pickupAgent->FirstName . ' ' . $this->pickupAgent->LastName)] : null,
            'collected_at'         => $this->collected_at,
            'closed_reason'        => $this->closed_reason,
            'history'              => $this->whenLoaded('transitions', fn () => $this->transitions->sortBy('id')->map(fn ($t) => [
                'from' => $t->from, 'to' => $t->to, 'reason' => $t->reason, 'at' => $t->created_at,
            ])->values()->all()),
            'created_at'           => $this->created_at,
        ];
    }
}
