<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
                // ── Données de base ──
                'id'                => $this->id,
                'uuid'              => $this->uuid,
                'OwnerFirstName'    => $this->OwnerFirstName,
                'OwnerLastName'     => $this->OwnerLastName,
                'DocIdentification' => $this->DocIdentification,
                'Location'          => $this->Location,
                'statut'            => $this->statut,
                'document_type_id'  => $this->document_type_id,
                'user_id'           => $this->user_id,
                'created_at'        => $this->created_at,
                'updated_at'        => $this->updated_at,
                'deleted_at'        => $this->deleted_at,

                // ── Relations ──
                'document_type' => $this->whenLoaded('documentType'),
                'user'          => $this->whenLoaded('user'),

                // ── URLs MediaLibrary ──
                // image          → URL originale
                // image_thumb    → 400x300 WebP (liste)
                // image_blurred  → floutée 80% (protection)
                // image_optimized→ 1200px 85% WebP (détail)
                'image'          => $this->getFirstMediaUrl('document_image'),
                'image_thumb'    => $this->getFirstMediaUrl('document_image', 'thumb'),
                'image_blurred'  => $this->getFirstMediaUrl('document_image', 'blurred'),
                'image_optimized'=> $this->getFirstMediaUrl('document_image', 'optimized'),
                ];
    }
}
