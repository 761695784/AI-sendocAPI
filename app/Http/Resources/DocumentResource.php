<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Publication d'un document trouvé.
 *
 * - Public / autres citoyens : image floutée uniquement, numéro masqué,
 *   publicateur réduit (prénom + initiale).
 * - Publicateur et Admin : numéro complet, coordonnées du publicateur,
 *   image originale via une URL signée temporaire (fichier sur disque privé).
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer     = $request->user('api');
        $privileged = $viewer && ($viewer->id === $this->user_id || $viewer->hasRole('Admin'));

        $blurred  = $this->getFirstMediaUrl('document_image', 'blurred') ?: null;
        $thumb    = $this->getFirstMediaUrl('document_image', 'thumb') ?: $blurred;
        $original = $privileged && $this->getFirstMedia('document_image')
            ? URL::temporarySignedRoute('documents.image', now()->addMinutes(15), ['uuid' => $this->uuid])
            : null;

        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'OwnerFirstName'    => $this->OwnerFirstName,
            'OwnerLastName'     => $this->OwnerLastName,
            'DocIdentification' => $privileged ? $this->DocIdentification : self::mask($this->DocIdentification),
            'Location'          => $this->Location,
            'statut'            => $this->statut,
            'document_type_id'  => $this->document_type_id,
            'user_id'           => $this->user_id,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
            'deleted_at'        => $this->deleted_at,
            'is_owner'          => (bool) ($viewer && $viewer->id === $this->user_id),

            'document_type' => $this->whenLoaded('documentType'),
            'user'          => $this->whenLoaded('user', fn () => $privileged
                ? $this->user
                : new PublicUserResource($this->user)),

            // Clés conservées pour compatibilité avec le front V1.
            'image'           => $original ?? $blurred,
            'image_thumb'     => $thumb,
            'image_blurred'   => $blurred,
            'image_optimized' => $original ?? $blurred,
        ];
    }

    /**
     * "1234567890123" → "•••••••••0123"
     */
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $visible = mb_substr($value, -4);

        return str_repeat('•', max(mb_strlen($value) - 4, 0)) . $visible;
    }
}
