<?php

namespace App\Services\Stats;

use App\Models\CollectionRequest;
use App\Models\DocumentMatch;
use App\Services\Notifications\Notifier;

/**
 * Relances automatiques (commande planifiée toutes les heures) :
 *  - collecte sans premier appel après first_contact_hours → relance à la structure ;
 *  - toujours rien après escalate_hours → alerte à la structure supérieure ;
 *  - correspondance à vérifier depuis review_hours → relance à la structure.
 * Chaque relance n'est envoyée qu'une fois par affectation / correspondance.
 */
class SlaMonitor
{
    public function __construct(private Notifier $notifier) {}

    /** @return array{reminders:int, escalations:int, review_reminders:int} */
    public function run(): array
    {
        $sla = config('doctrack.sla');
        $out = ['reminders' => 0, 'escalations' => 0, 'review_reminders' => 0];

        $late = CollectionRequest::with(['organization.parent', 'document'])
            ->where('status', CollectionRequest::STATUS_ASSIGNED)
            ->whereNull('first_contacted_at')
            ->whereNotNull('organization_id')
            ->where('assigned_at', '<=', now()->subHours($sla['first_contact_hours']))
            ->get();

        foreach ($late as $c) {
            $hours  = (int) $c->assigned_at->diffInHours(now());
            $params = [
                'reference' => $c->document?->reference,
                'place'     => $c->document?->found_place ?? $c->document?->Location ?? '—',
                'hours'     => $hours,
                'structure' => $c->organization->name,
            ];
            $link = 'collections/' . $c->uuid;
            $once = $c->id . ':' . $c->assigned_at->timestamp;

            if (! $c->reminded_at) {
                $this->notifier->inbox('collection.sla_reminder', $c->organization, $params,
                    ['subject' => $c, 'dedupe' => 'collection.sla_reminder:' . $once], $link, priority: true);
                $c->forceFill(['reminded_at' => now()])->save();
                $out['reminders']++;
            }

            if (! $c->escalated_at && $c->assigned_at->lte(now()->subHours($sla['escalate_hours']))) {
                $context = ['subject' => $c, 'organization_id' => $c->organization_id, 'dedupe' => 'collection.sla_escalation:' . $once];
                if ($parent = $c->organization->parent) {
                    $this->notifier->inbox('collection.sla_escalation', $parent, $params, $context, $link, priority: true);
                } else {
                    $this->notifier->inboxSuperAdmins('collection.sla_escalation', $params, $context, $link);
                }
                $c->forceFill(['escalated_at' => now()])->save();
                $out['escalations']++;
            }
        }

        $slowReviews = DocumentMatch::with(['organization', 'document'])
            ->where('status', DocumentMatch::STATUS_PENDING_REVIEW)
            ->whereNull('reminded_at')
            ->whereNotNull('organization_id')
            ->where('created_at', '<=', now()->subHours($sla['review_hours']))
            ->get();

        foreach ($slowReviews as $m) {
            $this->notifier->inbox('match.review_reminder', $m->organization, [
                'reference' => $m->document?->reference,
                'score'     => $m->score,
                'hours'     => (int) $m->created_at->diffInHours(now()),
            ], ['subject' => $m, 'dedupe' => 'match.review_reminder:' . $m->id], 'matches/' . $m->uuid, priority: true);
            $m->forceFill(['reminded_at' => now()])->save();
            $out['review_reminders']++;
        }

        return $out;
    }
}
