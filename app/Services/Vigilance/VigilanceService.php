<?php

namespace App\Services\Vigilance;

use App\Models\DeclarationDePerte;
use App\Models\DeclarationItem;
use App\Models\Document;
use App\Models\DocumentNumber;
use App\Models\OcrScan;
use App\Models\Organization;
use App\Models\User;
use App\Models\VigilanceAlert;
use App\Services\Matching\NameNormalizer;
use App\Services\Notifications\Notifier;
use App\Services\Ocr\ImageTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Vigilance : repère les doublons et les signaux de fraude, sans jamais
 * sanctionner automatiquement. Chaque alerte est expliquée (details) et
 * relue par un responsable, qui la confirme ou l'écarte.
 *
 * Seuils et niveaux : config/vigilance.php.
 */
class VigilanceService
{
    private const INACTIVE = [Document::LIFECYCLE_RESTITUTED, Document::LIFECYCLE_ARCHIVED, Document::LIFECYCLE_DESTROYED];

    public function __construct(private NameNormalizer $names, private Notifier $notifier) {}

    // ── Documents trouvés ────────────────────────────────────────────

    /** Doublons (numéro, identité, photo) et trouveurs en série. Ne lève jamais d'exception. */
    public function checkDocument(Document $document): void
    {
        try {
            $this->documentDuplicatesByNumber($document);
            $this->documentDuplicatesByIdentity($document);
            $this->documentPhoto($document);
            $this->serialFinder($document);
        } catch (Throwable $e) {
            Log::warning('Vigilance document en échec : ' . $e->getMessage(), ['document_id' => $document->id]);
        }
    }

    private function activeDocuments(Document $document)
    {
        return Document::query()
            ->where('id', '!=', $document->id)
            ->where('document_type_id', $document->document_type_id)
            ->whereNotIn('lifecycle_status', self::INACTIVE);
    }

    private function documentDuplicatesByNumber(Document $document): void
    {
        $hashes = $document->numberHashes();
        if (! $hashes) {
            return;
        }

        $ids = DocumentNumber::whereIn('hash', $hashes)->where('document_id', '!=', $document->id)->pluck('document_id');
        $others = $this->activeDocuments($document)
            ->where(fn ($q) => $q->whereIn('doc_number_hash', $hashes)->orWhereIn('id', $ids))
            ->get();

        foreach ($others as $other) {
            $this->raisePair($document, $other, 'duplicate_document', VigilanceAlert::LEVEL_WARNING, [
                'reason'  => 'même numéro de pièce',
                'message' => "Le document {$other->reference} porte le même numéro : probablement la même pièce publiée ou déposée deux fois.",
            ]);
        }
    }

    private function documentDuplicatesByIdentity(Document $document): void
    {
        if (! $document->birth_date_in_doc || ! $document->last_name_key || ! $document->first_name_key) {
            return;
        }

        $others = $this->activeDocuments($document)
            ->where('last_name_key', $document->last_name_key)
            ->where('first_name_key', $document->first_name_key)
            ->whereDate('birth_date_in_doc', $document->birth_date_in_doc)
            ->get();

        foreach ($others as $other) {
            $this->raisePair($document, $other, 'duplicate_document', VigilanceAlert::LEVEL_WARNING, [
                'reason'  => 'même titulaire (nom, prénom, date de naissance)',
                'message' => "Le document {$other->reference} semble être la même pièce (même titulaire, même date de naissance).",
            ]);
        }
    }

    /** Même photo déjà publiée : doublon si c'est le même titulaire, photo réutilisée (fraude) sinon. */
    private function documentPhoto(Document $document): void
    {
        $media = $document->getFirstMedia('document_image');
        if (! $media || ! is_file($media->getPath())) {
            return;
        }
        if (! $document->image_hash) {
            $hash = ImageTools::dHash($media->getPath());
            // Image unie (aucun détail) : empreinte sans valeur, on l'ignore.
            if ($hash === null || in_array($hash, ['0000000000000000', 'ffffffffffffffff'], true)) {
                return;
            }
            $document->forceFill(['image_hash' => $hash])->saveQuietly();
        }

        $max = (int) config('vigilance.photo_max_distance', 6);
        $candidates = Document::query()
            ->where('id', '!=', $document->id)
            ->whereNotNull('image_hash')
            ->where('created_at', '>=', now()->subDays((int) config('vigilance.photo_window_days', 365)))
            ->get(['id', 'reference', 'image_hash', 'OwnerFirstName', 'OwnerLastName', 'document_type_id', 'custody_organization_id', 'lifecycle_status']);

        foreach ($candidates as $other) {
            if (ImageTools::hammingDistance($document->image_hash, $other->image_hash) > $max) {
                continue;
            }
            $same = $this->names->similarity($document->OwnerLastName, $other->OwnerLastName) >= 0.85
                && $this->names->similarity($document->OwnerFirstName, $other->OwnerFirstName) >= 0.85;

            $same
                ? $this->raisePair($document, $other, 'duplicate_document', VigilanceAlert::LEVEL_WARNING, [
                    'reason'  => 'même photo',
                    'message' => "La même photo a déjà été publiée pour le document {$other->reference}.",
                ])
                : $this->raisePair($document, $other, 'reused_photo', VigilanceAlert::LEVEL_HIGH, [
                    'reason'  => 'photo identique, noms différents',
                    'message' => "La photo est identique à celle du document {$other->reference} ({$other->OwnerFirstName} {$other->OwnerLastName}) mais le nom saisi est différent : possible fausse publication.",
                ]);
        }
    }

    private function serialFinder(Document $document): void
    {
        if ($document->found_channel === Document::CHANNEL_COUNTER || ! $document->user_id) {
            return;
        }
        $days  = (int) config('vigilance.serial_finder.days', 7);
        $limit = (int) config('vigilance.serial_finder.count', 5);
        $count = Document::where('user_id', $document->user_id)
            ->where('found_channel', '!=', Document::CHANNEL_COUNTER)
            ->where('created_at', '>=', now()->subDays($days))->count();

        if ($count >= $limit && ($user = User::find($document->user_id))) {
            $this->raise($user, 'serial_finder', VigilanceAlert::LEVEL_INFO, [
                'count'   => $count,
                'days'    => $days,
                'message' => "{$count} documents publiés par le même compte en {$days} jours : bénévole très actif, ou circuit à vérifier.",
            ], null, 'serial_finder:u' . $user->id . ':' . now()->format('oW'));
        }
    }

    // ── Lecture OCR ──────────────────────────────────────────────────

    /** Incohérences relevées par la lecture et corrections suspectes. */
    public function checkOcr(Document $document, OcrScan $scan): void
    {
        $result = $scan->result ?? [];
        $issues = array_values(array_intersect($result['quality']['issues'] ?? [],
            ['mrz_invalid', 'name_mismatch', 'number_structure', 'birth_date_mismatch', 'sex_mismatch']));
        $numberChanged = in_array('doc_number', $scan->corrections ?? [], true) && ($result['mrz']['valid'] ?? false);

        if (! $issues && ! $numberChanged) {
            return;
        }

        $messages = array_values(array_filter(array_map(
            fn ($c) => in_array($c['code'], $issues, true) ? $c['message'] : null,
            $result['checks'] ?? []
        )));
        if ($numberChanged) {
            $messages[] = 'Le numéro lu dans la zone codée (validé par ses chiffres de contrôle) a été modifié avant publication.';
        }

        $this->raise($document, 'ocr_inconsistency', $numberChanged ? VigilanceAlert::LEVEL_HIGH : VigilanceAlert::LEVEL_WARNING, [
            'issues'   => $issues,
            'number_changed' => $numberChanged,
            'message'  => implode(' ', $messages),
            'ocr_scan' => $scan->uuid,
        ], $document->custody_organization_id, 'ocr:d' . $document->id);
    }

    // ── Déclarations ─────────────────────────────────────────────────

    public function checkDeclaration(DeclarationDePerte $declaration): void
    {
        try {
            $this->duplicateDeclaration($declaration);
            $this->manyIdentities($declaration);
        } catch (Throwable $e) {
            Log::warning('Vigilance déclaration en échec : ' . $e->getMessage(), ['declaration_id' => $declaration->id]);
        }
    }

    private function duplicateDeclaration(DeclarationDePerte $declaration): void
    {
        $owner = $declaration->user_id ? ['user_id', $declaration->user_id] : ($declaration->citizen_profile_id ? ['citizen_profile_id', $declaration->citizen_profile_id] : null);
        if (! $owner) {
            return;
        }

        foreach ($declaration->items as $item) {
            $other = DeclarationItem::query()
                ->where('document_type_id', $item->document_type_id)
                ->where('last_name_key', $item->last_name_key)
                ->where('first_name_key', $item->first_name_key)
                ->where('declaration_de_perte_id', '!=', $declaration->id)
                ->where('status', DeclarationItem::STATUS_LOST)
                ->whereHas('declaration', fn ($q) => $q->where($owner[0], $owner[1])
                    ->whereIn('status', [DeclarationDePerte::STATUS_REGISTERED, DeclarationDePerte::STATUS_MATCHED]))
                ->with('declaration')->first();

            if ($other) {
                $this->raise($declaration, 'duplicate_declaration', VigilanceAlert::LEVEL_INFO, [
                    'other'   => $other->declaration->reference,
                    'message' => "Même pièce déjà déclarée perdue par la même personne ({$other->declaration->reference}).",
                ], $declaration->organization_id, 'dup_decl:' . $declaration->id . ':' . $other->declaration_de_perte_id);
            }
        }
    }

    /** Un même compte qui déclare les pièces de nombreuses personnes différentes. */
    private function manyIdentities(DeclarationDePerte $declaration): void
    {
        if (! $declaration->user_id) {
            return;   // au guichet, l'agent déclare pour chacun : normal
        }
        $days  = (int) config('vigilance.many_identities.days', 30);
        $limit = (int) config('vigilance.many_identities.count', 4);

        $identities = DeclarationItem::whereHas('declaration', fn ($q) => $q->where('user_id', $declaration->user_id)
                ->where('created_at', '>=', now()->subDays($days)))
            ->get(['first_name_key', 'last_name_key'])
            ->map(fn ($i) => $i->first_name_key . '|' . $i->last_name_key)
            ->unique()->count();

        if ($identities >= $limit && ($user = User::find($declaration->user_id))) {
            $this->raise($user, 'many_identities', VigilanceAlert::LEVEL_WARNING, [
                'identities' => $identities,
                'days'       => $days,
                'message'    => "{$identities} identités différentes déclarées perdues par le même compte en {$days} jours.",
            ], null, 'many_identities:u' . $user->id . ':' . now()->format('Y-m'));
        }
    }

    // ── Restitution ──────────────────────────────────────────────────

    /** La personne présente comme pièce d'identité… le document qu'elle vient récupérer. */
    public function presentedSameDocument(Document $document, string $role): VigilanceAlert
    {
        return $this->raise($document, 'presented_same_document', VigilanceAlert::LEVEL_HIGH, [
            'role'    => $role,
            'message' => 'La pièce scannée pour prouver l\'identité porte le numéro du document à restituer (que la structure détient) : pièce falsifiée ou copie.',
        ], $document->custody_organization_id, 'same_doc:d' . $document->id . ':' . now()->format('YmdH'));
    }

    /** Alerte haute encore ouverte sur ce document (impose l'accord d'un responsable). */
    public function openHighAlert(Document $document): ?VigilanceAlert
    {
        return VigilanceAlert::open()->where('level', VigilanceAlert::LEVEL_HIGH)
            ->where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)
            ->latest('id')->first();
    }

    // ── Relecture ────────────────────────────────────────────────────

    public function review(VigilanceAlert $alert, User $reviewer, string $decision, ?string $note): VigilanceAlert
    {
        $alert->forceFill([
            'status'      => $decision,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        // Doublon confirmé : le plus récent des deux documents est archivé (il n'est plus proposé en correspondance).
        if ($decision === VigilanceAlert::STATUS_CONFIRMED && $alert->kind === 'duplicate_document'
            && $alert->subject instanceof Document && ($other = Document::find($alert->details['other_id'] ?? null))) {
            $newer = $alert->subject->id > $other->id ? $alert->subject : $other;
            $older = $newer->is($other) ? $alert->subject : $other;
            if (! in_array($newer->lifecycle_status, self::INACTIVE, true) && ! $newer->isInCustody()) {
                $newer->moveTo(Document::LIFECYCLE_ARCHIVED, 'Doublon de ' . $older->reference, $reviewer);
            }
        }

        activity()->causedBy($reviewer)->performedOn($alert)
            ->withProperties(['kind' => $alert->kind, 'decision' => $decision, 'note' => $note])
            ->log('Alerte de vigilance relue');

        return $alert;
    }

    // ── Outils ───────────────────────────────────────────────────────

    private function raisePair(Document $a, Document $b, string $kind, string $level, array $details): void
    {
        [$first, $second] = $a->id < $b->id ? [$a, $b] : [$b, $a];
        $this->raise($a, $kind, $level, $details + ['other_id' => $b->id, 'other_reference' => $b->reference],
            $a->custody_organization_id ?? $b->custody_organization_id,
            "{$kind}:d{$first->id}:d{$second->id}");
    }

    public function raise(Model $subject, string $kind, string $level, array $details, ?int $organizationId, string $dedupe): VigilanceAlert
    {
        $alert = VigilanceAlert::firstOrCreate(['dedupe_key' => mb_substr($dedupe, 0, 150)], [
            'subject_type'    => $subject->getMorphClass(),
            'subject_id'      => $subject->getKey(),
            'kind'            => $kind,
            'level'           => $level,
            'details'         => $details,
            'organization_id' => $organizationId,
        ]);

        if ($alert->wasRecentlyCreated && $level === VigilanceAlert::LEVEL_HIGH) {
            $params = [
                'kind'      => config("vigilance.labels.{$kind}", $kind),
                'reference' => $subject->reference ?? ('#' . $subject->getKey()),
                'message'   => $details['message'] ?? '',
            ];
            $organization = $organizationId ? Organization::find($organizationId) : null;
            $organization
                ? $this->notifier->inbox('vigilance.alert', $organization, $params, ['subject' => $alert], null, true)
                : $this->notifier->inboxSuperAdmins('vigilance.alert', $params, ['subject' => $alert]);
        }

        return $alert;
    }
}
