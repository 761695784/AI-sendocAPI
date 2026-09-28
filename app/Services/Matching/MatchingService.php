<?php

namespace App\Services\Matching;

use App\Models\DeclarationDePerte;
use App\Models\DeclarationItem;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\StatusTransition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Orchestration du matching :
 *   1. pré-filtrage des candidats (même type + numéro ou clé phonétique commune) ;
 *   2. score par le moteur (règles aujourd'hui, IA demain) ;
 *   3. enregistrement de la correspondance avec son statut.
 *
 * Une paire déjà rejetée, confirmée ou devenue sans objet n'est jamais recalculée.
 */
class MatchingService
{
    public function __construct(
        private MatchingEngine $engine,
        private MatchReviewService $review,
    ) {}

    /** Document trouvé → pièces déclarées perdues. @return Collection<DocumentMatch> */
    public function matchDocument(Document $document): Collection
    {
        if (! in_array($document->lifecycle_status, Document::MATCHABLE, true) || $document->trashed()) {
            return collect();
        }

        $keys = array_values(array_filter([$document->first_name_key, $document->last_name_key]));

        $items = DeclarationItem::query()
            ->with('declaration')
            ->where('document_type_id', $document->document_type_id)
            ->where('status', DeclarationItem::STATUS_LOST)
            ->whereHas('declaration', fn (Builder $q) => $q
                ->whereIn('status', [DeclarationDePerte::STATUS_REGISTERED, DeclarationDePerte::STATUS_MATCHED])
                // Pas de correspondance avec sa propre déclaration (V1 : même compte).
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $document->user_id)))
            ->where(function (Builder $q) use ($document, $keys) {
                if ($document->doc_number_hash) {
                    $q->orWhere('doc_number_hash', $document->doc_number_hash);
                }
                if ($keys) {
                    $q->orWhereIn('last_name_key', $keys)->orWhereIn('first_name_key', $keys);
                }
                if (! $document->doc_number_hash && ! $keys) {
                    $q->whereRaw('1 = 0');
                }
            })
            ->limit(200)
            ->get();

        $results = collect();
        foreach ($items as $item) {
            if ($match = $this->evaluate($item, $document)) {
                $results->push($match);
                if ($match->status === DocumentMatch::STATUS_CONFIRMED) {
                    break; // le document a trouvé son propriétaire
                }
            }
        }

        return $results;
    }

    /** Déclaration → documents trouvés (pour chaque pièce encore perdue). */
    public function matchDeclaration(DeclarationDePerte $declaration): Collection
    {
        if (! in_array($declaration->status, [DeclarationDePerte::STATUS_REGISTERED, DeclarationDePerte::STATUS_MATCHED], true)) {
            return collect();
        }

        $results = collect();

        foreach ($declaration->items()->where('status', DeclarationItem::STATUS_LOST)->get() as $item) {
            $item->setRelation('declaration', $declaration);
            $keys = array_values(array_filter([$item->first_name_key, $item->last_name_key]));

            $documents = Document::query()
                ->where('document_type_id', $item->document_type_id)
                ->whereIn('lifecycle_status', Document::MATCHABLE)
                // Pas sa propre publication ; les documents sans publicateur (QR, guichet) restent candidats.
                ->when($declaration->user_id, fn ($q) => $q->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $declaration->user_id)))
                ->where(function (Builder $q) use ($item, $keys) {
                    if ($item->doc_number_hash) {
                        $q->orWhere('doc_number_hash', $item->doc_number_hash);
                    }
                    if ($keys) {
                        $q->orWhereIn('last_name_key', $keys)->orWhereIn('first_name_key', $keys);
                    }
                    if (! $item->doc_number_hash && ! $keys) {
                        $q->whereRaw('1 = 0');
                    }
                })
                ->limit(200)
                ->get();

            foreach ($documents as $document) {
                if ($match = $this->evaluate($item, $document)) {
                    $results->push($match);
                    if ($match->status === DocumentMatch::STATUS_CONFIRMED) {
                        break; // cette pièce est retrouvée
                    }
                }
            }
        }

        return $results;
    }

    private function evaluate(DeclarationItem $item, Document $document): ?DocumentMatch
    {
        $existing = DocumentMatch::where('declaration_item_id', $item->id)->where('document_id', $document->id)->first();
        if ($existing && ! $existing->isOpen()) {
            return null; // décision humaine ou finale : on n'y revient pas
        }

        $result     = $this->engine->score($item, $document);
        $thresholds = config('matching.thresholds');

        $status = match (true) {
            $result->numberMatch && $result->nameScore >= 0.6 && $thresholds['auto_confirm_on_number_match'] => DocumentMatch::STATUS_CONFIRMED,
            $result->score >= $thresholds['review']  => DocumentMatch::STATUS_PENDING_REVIEW,
            $result->score >= $thresholds['propose'] => DocumentMatch::STATUS_PROPOSED,
            default => null,
        };

        if ($status === null) {
            // Plus assez proche (ex. données corrigées) : l'ancienne proposition devient sans objet.
            if ($existing) {
                $this->transition($existing, DocumentMatch::STATUS_SUPERSEDED, 'Score recalculé sous le seuil');
            }

            return null;
        }

        return DB::transaction(function () use ($existing, $item, $document, $result, $status) {
            $attributes = [
                'declaration_de_perte_id' => $item->declaration_de_perte_id,
                'organization_id'         => $document->custody_organization_id ?? $item->declaration?->organization_id,
                'engine'                  => $this->engine->name(),
                'engine_version'          => $this->engine->version(),
                'score'                   => $result->score,
                'score_details'           => $result->details,
                'number_match'            => $result->numberMatch,
            ];

            if ($existing) {
                $previous = $existing->status;
                $existing->fill($attributes + ['status' => $status])->save();
                $match = $existing;
                if ($previous !== $status) {
                    StatusTransition::record($match, $previous, $status, 'Score recalculé : ' . $result->score);
                }
            } else {
                $match = DocumentMatch::create($attributes + [
                    'declaration_item_id' => $item->id,
                    'document_id'         => $document->id,
                    'status'              => $status,
                ]);
                StatusTransition::record($match, null, $status, 'Score ' . $result->score);
            }

            if ($status === DocumentMatch::STATUS_PENDING_REVIEW) {
                $this->notifyReview($match, $item, $document);
            }

            // Collecte en cours : le propriétaire est probablement identifié → priorité.
            \App\Models\CollectionRequest::where('document_id', $document->id)->open()
                ->whereNull('owner_identified_at')->update(['owner_identified_at' => now()]);

            if ($status === DocumentMatch::STATUS_CONFIRMED) {
                $match->forceFill(['review_note' => 'Confirmée automatiquement : numéro de document identique'])->save();
                $this->review->applyConfirmation($match);
            }

            return $match;
        });
    }

    private function transition(DocumentMatch $match, string $to, string $reason): void
    {
        $from = $match->status;
        $match->update(['status' => $to]);
        StatusTransition::record($match, $from, $to, $reason);
    }

    /** Nouvelle correspondance à vérifier : message dans la boîte de la structure (une fois). */
    private function notifyReview(DocumentMatch $match, DeclarationItem $item, Document $document): void
    {
        $params  = [
            'score'                 => $match->score,
            'declaration_reference' => $item->declaration?->reference ?? '—',
            'reference'             => $document->reference,
            'owner_name'            => $document->OwnerFirstName . ' ' . $document->OwnerLastName,
        ];
        $context = ['subject' => $match, 'dedupe' => 'match.pending_review:' . $match->id];
        $notifier = app(\App\Services\Notifications\Notifier::class);

        if ($match->organization) {
            $notifier->inbox('match.pending_review', $match->organization, $params, $context, 'matches/' . $match->uuid);
        } else {
            $notifier->inboxSuperAdmins('match.pending_review', $params, $context, 'matches/' . $match->uuid);
        }
    }
}
