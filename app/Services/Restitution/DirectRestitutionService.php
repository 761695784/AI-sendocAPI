<?php

namespace App\Services\Restitution;

use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\RestitutionRequest;
use App\Models\StatusTransition;
use App\Models\User;
use App\Notifications\RestitutionRequestNotification;
use App\Notifications\RestitutionUpdateNotification;
use Illuminate\Support\Facades\DB;

/**
 * Demande de restitution par le propriétaire, selon qui détient le document :
 *
 *  1. Publié par un citoyen (trouveur) — mise en relation directe :
 *     - le demandeur prouve que c'est sa pièce (correspondance confirmée, même nom que son compte,
 *       ou 4 derniers caractères du numéro) et accepte les conditions (DocTrack ne garantit pas la rencontre) ;
 *     - il reçoit aussitôt le numéro du trouveur ; le trouveur reçoit un SMS et un email avec le sien ;
 *     - une fois le document remis, le trouveur marque sa publication « récupéré ».
 *  2. Conservé par une structure (police, gendarmerie) — retrait au guichet :
 *     - la structure est prévenue (boîte de réception, email, SMS) ;
 *     - la personne s'y présente avec une pièce justificative et reçoit la décharge de restitution.
 */
class DirectRestitutionService
{
    public function __construct(private \App\Services\Notifications\Notifier $notifier) {}

    /** Correspondance confirmée entre ce document et une déclaration de ce citoyen. */
    public function confirmedMatchFor(Document $document, User $requester): ?DocumentMatch
    {
        return $document->matches()
            ->where('status', DocumentMatch::STATUS_CONFIRMED)
            ->whereHas('declaration', fn ($q) => $q->where('user_id', $requester->id))
            ->first();
    }

    public function activeRequest(Document $document): ?RestitutionRequest
    {
        return $document->restitutionRequests()->active()->latest('id')->first();
    }

    /**
     * Le demandeur a-t-il PROUVÉ qu'il est le propriétaire (mise en relation immédiate) ?
     *  - correspondance confirmée par DocTrack ;
     *  - OU 4 derniers caractères exacts du numéro de la pièce (si le trouveur l'a saisi). Ils ne sont
     *    jamais affichés au public (DocumentResource) : seul le vrai titulaire les connaît.
     * Le nom du compte n'est PAS une preuve : n'importe qui peut s'inscrire sous le nom affiché sur la
     * liste publique. Un nom concordant envoie la demande au trouveur (voir nameMatches()).
     */
    public function ownershipLooksRight(Document $document, User $requester, ?string $last4 = null): bool
    {
        if ($this->confirmedMatchFor($document, $requester)) {
            return true;
        }

        $given = \App\Support\SensitiveData::normalizeDocNumber($last4);
        $known = $this->knownLast4($document);

        return $given !== null && strlen($given) >= 4 && $known !== null && substr($given, -4) === $known;
    }

    /** 4 derniers caractères du numéro du document, s'il a été saisi par le trouveur. */
    public function knownLast4(Document $document): ?string
    {
        $known = $document->doc_number_last4
            ?: substr((string) \App\Support\SensitiveData::normalizeDocNumber($document->DocIdentification), -4);

        return $known !== '' ? $known : null;
    }

    /**
     * Le nom du compte est-il proche de celui du document ? Comparaison tolérante aux usages sénégalais :
     * deux noms de famille (« Diop Ndiaye » / « Ndiaye »), prénoms composés (« Mame Fatou » / « Fatou »),
     * graphies (« Ndiaye » / « Njaay »), accents, ordre des mots. Simple indice : la demande part alors
     * au trouveur, qui compare avec la pièce qu'il a en main (photo comprise) avant d'accepter.
     */
    public function nameMatches(Document $document, User $requester): bool
    {
        return $this->namesLookAlike($document, $requester);
    }

    private function namesLookAlike(Document $document, User $requester): bool
    {
        $names = app(\App\Services\Matching\NameNormalizer::class);
        $keys  = fn (?string $v) => array_values(array_unique(array_map(fn ($t) => $names->tokenKey($t), $names->tokens($v))));

        $docLast  = $keys($document->OwnerLastName);
        $userLast = $keys($requester->LastName);
        // Au moins un nom de famille en commun (deux noms successifs d'un côté, un seul de l'autre).
        $lastOk = $docLast && $userLast && (array_intersect($docLast, $userLast) || $names->similarity($document->OwnerLastName, $requester->LastName) >= 0.88);

        // Prénom : composé ou non, variantes de graphie.
        $firstOk = $names->similarity($document->OwnerFirstName, $requester->FirstName) >= 0.85
            || (bool) array_intersect($keys($document->OwnerFirstName), $keys($requester->FirstName));

        if ($lastOk && $firstOk) {
            return true;
        }

        // Prénom et nom inversés au moment de la publication.
        $full = fn (?string $a, ?string $b) => trim($a . ' ' . $b);

        return $names->similarity($full($document->OwnerFirstName, $document->OwnerLastName), $full($requester->LastName, $requester->FirstName)) >= 0.9;
    }

    /**
     * Rien ne prouve automatiquement que c'est sa pièce (nom différent, numéro absent ou inconnu) :
     * la demande est envoyée au trouveur, qui a le document en main et décide. S'il accepte, les numéros
     * sont échangés ; sinon (ou sans réponse sous 48 h), une structure prend le relais.
     */
    public function requestToFinder(Document $document, User $requester): RestitutionRequest
    {
        $request = DB::transaction(function () use ($document, $requester) {
            $request = RestitutionRequest::create([
                'channel'           => RestitutionRequest::CHANNEL_DIRECT,
                'document_id'       => $document->id,
                'requester_user_id' => $requester->id,
                'finder_user_id'    => $document->user_id,
                'status'            => RestitutionRequest::STATUS_PENDING,
                'expires_at'        => now()->addHours(config('doctrack.restitution.response_hours', 48)),
                'terms_accepted_at' => now(),
            ]);
            StatusTransition::record($request, null, RestitutionRequest::STATUS_PENDING,
                'Demande à vérifier par le trouveur (identité non confirmée automatiquement)', $requester);

            activity()->causedBy($requester)->performedOn($document)
                ->withProperties(['request' => $request->uuid, 'channel' => 'direct', 'check' => 'finder'])
                ->log('Demande de restitution envoyée (à vérifier par le trouveur)');

            return $request;
        });

        $finder = $document->user;
        if ($finder) {
            $this->notifier->mail('restitution.requested', $finder,
                new RestitutionRequestNotification($requester, $document, false, $request, null),
                ['subject' => $request, 'recipient' => $finder]);
            $this->notifier->sms('restitution.requested.check', $this->phoneOf($finder, $document), [
                'owner_name'       => trim($document->OwnerFirstName . ' ' . $document->OwnerLastName),
                'requester_name'   => trim($requester->FirstName . ' ' . $requester->LastName),
                'url'              => $this->link($request),
            ], ['subject' => $request, 'recipient' => $finder]);
        }

        return $request;
    }

    /**
     * Cas 1 — document publié par un citoyen : mise en relation immédiate.
     * Le demandeur a accepté les conditions (rencontre sous sa responsabilité).
     */
    public function requestDirect(Document $document, User $requester, ?DocumentMatch $match): RestitutionRequest
    {
        $request = DB::transaction(function () use ($document, $requester, $match) {
            $request = RestitutionRequest::create([
                'channel'           => RestitutionRequest::CHANNEL_DIRECT,
                'document_id'       => $document->id,
                'document_match_id' => $match?->id,
                'requester_user_id' => $requester->id,
                'finder_user_id'    => $document->user_id,
                'status'            => RestitutionRequest::STATUS_ACCEPTED,
                'contact_shared_at' => now(),
                'terms_accepted_at' => now(),
            ]);
            StatusTransition::record($request, null, RestitutionRequest::STATUS_ACCEPTED,
                'Demande du propriétaire : coordonnées échangées (conditions acceptées)', $requester);

            $this->noteOnCollection($document, $match
                ? 'Le propriétaire confirmé a demandé une remise directe au trouveur'
                : 'Le titulaire (nom ou numéro vérifié) a demandé une remise directe au trouveur', $requester);

            activity()->causedBy($requester)->performedOn($document)
                ->withProperties(['request' => $request->uuid, 'match' => $match?->uuid, 'channel' => 'direct'])
                ->log('Demande de restitution envoyée');

            return $request;
        });

        $finder      = $document->user;
        $finderPhone = $finder ? $this->phoneOf($finder, $document) : null;
        $ownerName   = trim($document->OwnerFirstName . ' ' . $document->OwnerLastName);

        if ($finder) {
            $this->notifier->mail('restitution.requested', $finder,
                new RestitutionRequestNotification($requester, $document, (bool) $match, $request, $requester->Phone),
                ['subject' => $request, 'recipient' => $finder]);
            $this->notifier->sms('restitution.contact.finder', $finderPhone, [
                'owner_name'       => $ownerName,
                'owner_first_name' => $requester->FirstName,
                'owner_phone'      => $requester->Phone,
            ], ['subject' => $request, 'recipient' => $finder]);
        }
        $this->notifier->sms('restitution.contact.owner', $requester->Phone, [
            'owner_name'   => $ownerName,
            'finder_name'  => $finder?->FirstName ?? 'le trouveur',
            'finder_phone' => $finderPhone ?? '—',
        ], ['subject' => $request, 'recipient' => $requester]);

        return $request;
    }

    /**
     * Cas 2 — document conservé par une structure : la structure est prévenue,
     * la personne vient le retirer au guichet (décharge de restitution).
     */
    public function requestAtStructure(Document $document, User $requester, \App\Models\Organization $structure): RestitutionRequest
    {
        $request = DB::transaction(function () use ($document, $requester, $structure) {
            $request = RestitutionRequest::create([
                'channel'           => RestitutionRequest::CHANNEL_COUNTER,
                'document_id'       => $document->id,
                'requester_user_id' => $requester->id,
                'finder_user_id'    => null,
                'organization_id'   => $structure->id,
                'status'            => RestitutionRequest::STATUS_PENDING,
            ]);
            StatusTransition::record($request, null, RestitutionRequest::STATUS_PENDING,
                'Demande de retrait au guichet de ' . $structure->name, $requester, $structure->id);

            activity()->causedBy($requester)->performedOn($document)
                ->withProperties(['request' => $request->uuid, 'channel' => 'counter', 'organization' => $structure->uuid])
                ->log('Demande de restitution envoyée à la structure');

            return $request;
        });

        $params = [
            'reference'     => $document->reference,
            'document_type' => $document->documentType?->TypeName ?? 'Document',
            'owner_name'    => trim($document->OwnerFirstName . ' ' . $document->OwnerLastName),
            'requester'     => trim($requester->FirstName . ' ' . $requester->LastName),
            'structure'     => $structure->name,
            'address'       => $structure->address ?: $structure->name,
            'structure_phone' => $structure->phone ? ' (' . $structure->phone . ')' : '',
        ];
        $context = ['subject' => $request, 'organization_id' => $structure->id];

        $this->notifier->inbox('restitution.counter_request', $structure, $params, $context, 'documents/' . $document->uuid, true);
        $this->notifier->mailTo('restitution.counter_request', $structure->email, $params, $context);
        $this->notifier->sms('restitution.counter_request.structure', $structure->phone, $params, $context);
        $this->notifier->sms('restitution.counter_request.owner', $requester->Phone, $params, ['subject' => $request, 'recipient' => $requester]);

        return $request;
    }

    /** Le trouveur accepte : échange des numéros. */
    public function accept(RestitutionRequest $request, User $finder): RestitutionRequest
    {
        $request = DB::transaction(function () use ($request, $finder) {
            $request->fill([
                'status'            => RestitutionRequest::STATUS_ACCEPTED,
                'responded_at'      => now(),
                'contact_shared_at' => now(),
            ])->save();
            StatusTransition::record($request, RestitutionRequest::STATUS_PENDING, RestitutionRequest::STATUS_ACCEPTED, 'Le trouveur accepte la mise en contact', $finder);
            $this->noteOnCollection($request->document, 'Le trouveur a accepté une remise directe au propriétaire', $finder);

            return $request->fresh(['document', 'requester', 'finder']);
        });

        $doc        = $request->document;
        $owner      = $request->requester;
        $ownerPhone = $owner->Phone;
        $finderPhone = $this->phoneOf($request->finder, $doc);
        $what       = 'le document au nom de ' . $doc->OwnerFirstName . ' ' . $doc->OwnerLastName;

        $params = [
            'owner_name'       => $doc->OwnerFirstName . ' ' . $doc->OwnerLastName,
            'owner_first_name' => $owner->FirstName,
            'owner_phone'      => $ownerPhone,
            'finder_name'      => $request->finder->FirstName,
            'finder_phone'     => $finderPhone,
        ];
        $this->notifier->sms('restitution.accepted.owner', $ownerPhone, $params, ['subject' => $request, 'recipient' => $owner]);
        $this->notifier->sms('restitution.accepted.finder', $finderPhone, $params, ['subject' => $request, 'recipient' => $request->finder]);

        $this->notifier->mail('restitution.accepted', $owner, new RestitutionUpdateNotification($request, RestitutionUpdateNotification::CONTACT_FOR_OWNER, $finderPhone), ['subject' => $request]);
        $this->notifier->mail('restitution.accepted', $request->finder, new RestitutionUpdateNotification($request, RestitutionUpdateNotification::CONTACT_FOR_FINDER, $ownerPhone), ['subject' => $request]);

        return $request;
    }

    /** Refus du trouveur (ou délai dépassé) : la structure la plus proche prend le relais. */
    public function decline(RestitutionRequest $request, ?User $finder, ?string $reason, bool $expired = false): RestitutionRequest
    {
        $to = $expired ? RestitutionRequest::STATUS_EXPIRED : RestitutionRequest::STATUS_DECLINED;

        $from = $request->status;
        $request->fill(['status' => $to, 'responded_at' => $expired ? null : now(), 'decline_reason' => $reason])->save();
        StatusTransition::record($request, $from, $to,
            $expired ? 'Pas de réponse du trouveur dans le délai' : 'Refus du trouveur' . ($reason ? ' : ' . $reason : ''), $finder);

        $doc        = $request->document;
        $collection = $doc->openCollection();
        $this->noteOnCollection($doc, 'Remise directe impossible (' . ($expired ? 'sans réponse' : 'refus') . ') : collecte à traiter en priorité', $finder);
        $collection?->forceFill(['owner_identified_at' => $collection->owner_identified_at ?? now()])->save();

        $next = $collection?->organization
            ? $collection->organization->name . ' va récupérer le document auprès du trouveur ; vous serez prévenu.'
            : 'Vous pouvez signaler la situation à la structure de police ou de gendarmerie la plus proche avec la référence ' . $doc->reference . '.';

        $event = $expired ? 'restitution.expired' : 'restitution.declined';
        $this->notifier->sms($event, $request->requester->Phone, ['next' => $next], ['subject' => $request, 'recipient' => $request->requester]);
        $this->notifier->mail($event, $request->requester, new RestitutionUpdateNotification($request, $expired ? RestitutionUpdateNotification::EXPIRED : RestitutionUpdateNotification::DECLINED, $next), ['subject' => $request]);

        return $request->fresh();
    }

    public function cancel(RestitutionRequest $request, ?User $actor, string $reason): void
    {
        $from = $request->status;
        $request->fill(['status' => RestitutionRequest::STATUS_CANCELLED, 'decline_reason' => $reason])->save();
        StatusTransition::record($request, $from, RestitutionRequest::STATUS_CANCELLED, $reason, $actor);
    }

    /** Remise déclarée par le trouveur : la demande est close. */
    public function completeForDocument(Document $document, ?User $actor): void
    {
        $document->restitutionRequests()->active()->get()->each(function (RestitutionRequest $r) use ($actor) {
            $from = $r->status;
            $r->fill(['status' => RestitutionRequest::STATUS_COMPLETED, 'completed_at' => now()])->save();
            StatusTransition::record($r, $from, RestitutionRequest::STATUS_COMPLETED, 'Remise déclarée par le trouveur', $actor);
        });
    }

    /** Restitution officielle au guichet : la demande de retrait est close (les mises en relation sont annulées). */
    public function closeAfterCounterRestitution(Document $document, ?User $actor): void
    {
        $document->restitutionRequests()->active()->get()->each(function (RestitutionRequest $r) use ($actor) {
            if ($r->channel === RestitutionRequest::CHANNEL_COUNTER) {
                $from = $r->status;
                $r->fill(['status' => RestitutionRequest::STATUS_COMPLETED, 'completed_at' => now()])->save();
                StatusTransition::record($r, $from, RestitutionRequest::STATUS_COMPLETED, 'Document remis au guichet', $actor, $r->organization_id);
            } else {
                $this->cancel($r, $actor, 'Document restitué au guichet');
            }
        });
    }

    public function cancelForDocument(Document $document, ?User $actor, string $reason): void
    {
        $document->restitutionRequests()->active()->get()->each(fn ($r) => $this->cancel($r, $actor, $reason));
    }

    /** Demandes sans réponse dans le délai. */
    public function expireOverdue(): int
    {
        $overdue = RestitutionRequest::where('status', RestitutionRequest::STATUS_PENDING)
            ->where('expires_at', '<', now())->get();
        $overdue->each(fn ($r) => $this->decline($r, null, null, expired: true));

        return $overdue->count();
    }

    public function link(RestitutionRequest $request): string
    {
        // Page « Remises » de l'espace citoyen, avec la demande concernée (ouverte directement).
        return rtrim(config('doctrack.frontend_url'), '/') . '/mon-espace/restitutions?demande=' . $request->uuid;
    }

    /** Numéro du trouveur : celui du compte, sinon celui donné pour la collecte. */
    private function phoneOf(User $finder, Document $document): ?string
    {
        return $finder->Phone ?: $document->collectionRequests()->latest('id')->first()?->finderPhone();
    }

    private function noteOnCollection(Document $document, string $reason, ?User $actor): void
    {
        if ($collection = $document->openCollection()) {
            StatusTransition::record($collection, $collection->status, $collection->status, $reason, $actor, $collection->organization_id);

            if ($collection->organization) {
                $this->notifier->inbox('collection.direct_restitution', $collection->organization,
                    ['reference' => $document->reference, 'detail' => $reason . '.'],
                    ['subject' => $collection], 'collections/' . $collection->uuid);
            }
        }
    }
}
