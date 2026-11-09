<?php

namespace App\Services\Matching;

use App\Models\DeclarationDePerte;
use App\Models\DeclarationItem;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\StatusTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Décision humaine sur une correspondance.
 * Confirmer ne donne AUCUN droit sur le document : la restitution exigera
 * une vérification d'identité (Phase 5).
 */
class MatchReviewService
{
    public function confirm(DocumentMatch $match, User $agent, ?string $note = null): DocumentMatch
    {
        return DB::transaction(function () use ($match, $agent, $note) {
            $from = $match->status;
            $match->update([
                'status'      => DocumentMatch::STATUS_CONFIRMED,
                'reviewed_by' => $agent->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);
            StatusTransition::record($match, $from, DocumentMatch::STATUS_CONFIRMED, $note, $agent);

            activity()->causedBy($agent)->performedOn($match)
                ->withProperties(['score' => $match->score, 'note' => $note])
                ->log('Correspondance confirmée');

            $this->applyConfirmation($match, $agent);

            return $match->fresh();
        });
    }

    public function reject(DocumentMatch $match, User $agent, string $reason): DocumentMatch
    {
        return DB::transaction(function () use ($match, $agent, $reason) {
            $from = $match->status;
            $match->update([
                'status'      => DocumentMatch::STATUS_REJECTED,
                'reviewed_by' => $agent->id,
                'reviewed_at' => now(),
                'review_note' => $reason,
            ]);
            StatusTransition::record($match, $from, DocumentMatch::STATUS_REJECTED, $reason, $agent);

            activity()->causedBy($agent)->performedOn($match)
                ->withProperties(['score' => $match->score, 'reason' => $reason])
                ->log('Correspondance rejetée');

            return $match->fresh();
        });
    }

    /**
     * Effets d'une correspondance confirmée :
     *  - la pièce est "trouvée", la déclaration passe à "matched",
     *  - le document passe à "matched",
     *  - les autres propositions ouvertes sur cette pièce ou ce document deviennent sans objet.
     */
    public function applyConfirmation(DocumentMatch $match, ?User $agent = null): void
    {
        $match->loadMissing(['item', 'declaration', 'document']);

        $match->item?->update(['status' => DeclarationItem::STATUS_FOUND]);

        if ($match->declaration && $match->declaration->status === DeclarationDePerte::STATUS_REGISTERED) {
            $match->declaration->moveTo(DeclarationDePerte::STATUS_MATCHED, 'Correspondance confirmée', $agent);
        }

        if ($match->document && in_array($match->document->lifecycle_status, Document::MATCHABLE, true)) {
            $match->document->moveTo(Document::LIFECYCLE_MATCHED, 'Correspondance confirmée', $agent);
        }

        DocumentMatch::query()
            ->whereKeyNot($match->id)
            ->whereIn('status', DocumentMatch::OPEN_STATUSES)
            ->where(fn ($q) => $q->where('declaration_item_id', $match->declaration_item_id)->orWhere('document_id', $match->document_id))
            ->get()
            ->each(function (DocumentMatch $other) use ($agent) {
                $from = $other->status;
                $other->update(['status' => DocumentMatch::STATUS_SUPERSEDED]);
                StatusTransition::record($other, $from, DocumentMatch::STATUS_SUPERSEDED, 'Autre correspondance confirmée', $agent);
            });

        // Le propriétaire est prévenu (email + SMS) : restitution par le trouveur ou au guichet.
        \App\Jobs\NotifyOwnerOfConfirmedMatchJob::dispatch($match->id)->afterCommit();
    }

    /**
     * Remise effectuée (déclarée par les parties) : pièce et déclaration restituées.
     */
    public function applyRestitution(Document $document, ?User $actor, string $reason): void
    {
        $document->matches()->where('status', DocumentMatch::STATUS_CONFIRMED)->with(['item', 'declaration'])->get()
            ->each(function (DocumentMatch $m) use ($actor, $reason) {
                $m->item?->update(['status' => DeclarationItem::STATUS_RESTITUTED]);
                $declaration = $m->declaration;
                if ($declaration && ! $declaration->items()->where('status', '!=', DeclarationItem::STATUS_RESTITUTED)->exists()) {
                    $declaration->moveTo(DeclarationDePerte::STATUS_RESTITUTED, $reason, $actor);
                    // Invitation à raconter son expérience (une seule fois, sans bloquer la restitution).
                    app(\App\Services\Testimonials\TestimonialService::class)->inviteAfterRestitution($declaration);
                }
            });
    }

    /** Déclaration annulée ou supprimée : ses propositions ouvertes deviennent sans objet. */
    public function supersedeForDeclaration(DeclarationDePerte $declaration, ?User $agent = null, string $reason = 'Déclaration annulée'): void
    {
        $this->supersedeAll($declaration->matches()->whereIn('status', DocumentMatch::OPEN_STATUSES)->get(), $agent, $reason);
    }

    /** Document restitué, supprimé ou détruit : ses propositions ouvertes deviennent sans objet. */
    public function supersedeForDocument(Document $document, ?User $agent = null, string $reason = 'Document retiré'): void
    {
        $this->supersedeAll($document->matches()->whereIn('status', DocumentMatch::OPEN_STATUSES)->get(), $agent, $reason);
    }

    private function supersedeAll($matches, ?User $agent, string $reason): void
    {
        $matches->each(function (DocumentMatch $m) use ($agent, $reason) {
            $from = $m->status;
            $m->update(['status' => DocumentMatch::STATUS_SUPERSEDED]);
            StatusTransition::record($m, $from, DocumentMatch::STATUS_SUPERSEDED, $reason, $agent);
        });
    }
}
