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
        $privileged = $viewer && ($viewer->id === $this->user_id || $viewer->isSuperAdmin());

        // Version masquée (numéros cachés par l'OCR) en priorité, sinon la photo floutée.
        // Original marqué « masqué » sans sa copie masquée (masquage interrompu) : aucune image publique,
        // plutôt qu'un lien qui renverrait une erreur (image cassée dans la liste).
        $publicMedia = $this->getFirstMedia('document_public');
        if (! $publicMedia) {
            $source      = $this->getFirstMedia('document_image');
            $publicMedia = $source && $source->getCustomProperty('public_version') !== 'masked' ? $source : null;
        }
        // Seules les versions réellement générées sont renvoyées : un lien vers une conversion absente
        // (extension d'image manquante sur le serveur, file d'attente non lancée) donnait une image cassée.
        // Adresse servie par l'API elle-même (route documents.public-image) : l'image s'affiche même si
        // APP_URL ou le lien « storage » ne correspondent pas, et une version floutée est produite si besoin.
        $version = $publicMedia ? '?v=' . ($publicMedia->updated_at?->timestamp ?? 0) : '';
        $blurred = $publicMedia ? route('documents.public-image', ['media' => $publicMedia->id, 'variant' => 'blurred']) . $version : null;
        $thumb   = $publicMedia ? route('documents.public-image', ['media' => $publicMedia->id, 'variant' => 'thumb']) . $version : null;
        $original = $privileged && $this->getFirstMedia('document_image')
            ? URL::temporarySignedRoute('documents.image', now()->addMinutes(15), ['uuid' => $this->uuid])
            : null;

        $counter = $this->found_channel === \App\Models\Document::CHANNEL_COUNTER;
        // Guichet : le numéro n'existe que chiffré ; on n'affiche que les 4 derniers chiffres.
        $number = $counter
            ? ($this->doc_number_last4 ? '••••' . $this->doc_number_last4 : null)
            : ($privileged ? $this->DocIdentification : self::mask($this->DocIdentification));

        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'reference'         => $this->reference,
            'found_channel'     => $this->found_channel ?? \App\Models\Document::CHANNEL_CITIZEN,
            'lifecycle_status'  => $this->lifecycle_status,
            // Où récupérer le document (déposé dans une structure officielle).
            'custody'           => $this->custody_organization_id
                ? $this->custodyOrganization?->only(['uuid', 'name', 'address', 'phone'])
                : null,
            'OwnerFirstName'    => $this->OwnerFirstName,
            'OwnerLastName'     => $this->OwnerLastName,
            'DocIdentification' => $number,
            'Location'          => $this->Location,
            'statut'            => $this->statut,
            'document_type_id'  => $this->document_type_id,
            'user_id'           => $this->user_id,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
            'deleted_at'        => $this->deleted_at,
            'is_owner'          => (bool) ($viewer && $viewer->id === $this->user_id),

            'document_type' => $this->whenLoaded('documentType'),
            // Guichet : l'agent déposant n'est jamais exposé côté citoyen.
            'user'          => $this->whenLoaded('user', fn () => ($counter || ! $this->user)
                ? null
                : ($privileged ? $this->user : new PublicUserResource($this->user))),

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
