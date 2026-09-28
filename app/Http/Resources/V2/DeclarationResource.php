<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Déclaration vue par un agent habilité (numéros complets : il en a la garde).
 * En liste ($this->additional['summary']), les numéros sont masqués.
 */
class DeclarationResource extends JsonResource
{
    public bool $full = true;

    public function toArray(Request $request): array
    {
        $cert = $this->certificat;

        return [
            'uuid'          => $this->uuid,
            'reference'     => $this->reference,
            'channel'       => $this->channel,
            'status'        => $this->status,
            'lost_at'       => $this->lost_at?->toDateString(),
            'lost_place'    => $this->lost_place,
            'circumstances' => $this->when($this->full, $this->circumstances),
            'territory'     => $this->whenLoaded('territory', fn () => $this->territory ? ['code' => $this->territory->code, 'name' => $this->territory->name] : null),
            'citizen'       => $this->whenLoaded('citizenProfile', fn () => $this->citizenProfile ? (new CitizenProfileResource($this->citizenProfile))->resolve() : null),
            'organization'  => $this->whenLoaded('organization', fn () => $this->organization ? ['uuid' => $this->organization->uuid, 'name' => $this->organization->name] : null),
            'recorded_by'   => $this->whenLoaded('recordedBy', fn () => $this->recordedBy ? [
                'uuid' => $this->recordedBy->uuid,
                'name' => trim($this->recordedBy->FirstName . ' ' . $this->recordedBy->LastName),
            ] : null),
            'items'         => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'uuid'          => $item->uuid,
                'document_type' => ['id' => $item->document_type_id, 'name' => $item->documentType?->TypeName],
                'first_name'    => $item->first_name,
                'last_name'     => $item->last_name,
                'birth_date'    => $item->birth_date_in_doc?->toDateString(),
                'doc_number'    => $this->full ? $item->docNumber() : ($item->doc_number_last4 ? '••••' . $item->doc_number_last4 : null),
                'status'        => $item->status,
            ])->all()),
            'certificate'   => $cert ? [
                'number'            => $cert->certificat_number,
                'kind'              => $cert->kind,
                'verification_code' => $cert->formattedVerificationCode(),
                'verification_url'  => $cert->verificationUrl(),
                'revoked_at'        => $cert->revoked_at,
                'pdf_url'           => $this->full && $cert->pdf_path
                    ? URL::temporarySignedRoute('files.certificate', now()->addMinutes(15), ['uuid' => $cert->uuid])
                    : null,
            ] : null,
            // Frais de la déclaration (en mode live : le certificat attend le règlement).
            'charge'         => $this->whenLoaded('charges', fn () => ($c = $this->charges->sortByDesc('id')->first()) ? [
                'uuid'        => $c->uuid,
                'reference'   => $c->reference,
                'status'      => $c->status,
                'amount'      => $c->amount,
                'list_amount' => $c->list_amount,
                'currency'    => $c->currency,
            ] : null),
            'closed_at'      => $this->closed_at,
            'closure_reason' => $this->closure_reason,
            'created_at'     => $this->created_at,
        ];
    }

    public function summary(): static
    {
        $this->full = false;

        return $this;
    }
}
