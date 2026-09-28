<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\NotificationDelivery;
use App\Services\Access\OrganizationAccess;
use App\Services\Notifications\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Suivi des envois (SMS, emails, boîte de réception) : preuve de qui a été
 * prévenu et quand. Super admin : tout ; responsables : leur périmètre.
 */
class NotificationDeliveryController extends Controller
{
    public function __construct(private OrganizationAccess $access, private Notifier $notifier) {}

    public function index(Request $request)
    {
        $page = $this->scoped($request)
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('channel'), fn ($q, $v) => $q->where('channel', $v))
            ->when($request->query('event'), fn ($q, $v) => $q->where('event', $v))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('id')
            ->paginate(50);

        $page->getCollection()->transform(fn (NotificationDelivery $d) => [
            'uuid'        => $d->uuid,
            'event'       => $d->event,
            'channel'     => $d->channel,
            'destination' => $d->destination_masked,
            'status'      => $d->status,
            'attempts'    => $d->attempts,
            'error'       => $d->error,
            'preview'     => $d->previewText(),
            'subject'     => $d->subject_type ? class_basename($d->subject_type) : null,
            'sent_at'     => $d->sent_at,
            'created_at'  => $d->created_at,
        ]);

        return response()->json($page);
    }

    /** Tableau de bord : volumes et taux d'échec sur la période. */
    public function summary(Request $request)
    {
        $days  = min(max((int) $request->query('days', 30), 1), 365);
        $base  = $this->scoped($request)->where('created_at', '>=', now()->subDays($days));

        $byChannel = (clone $base)->select('channel', 'status', DB::raw('count(*) as total'))
            ->groupBy('channel', 'status')->get()
            ->groupBy('channel')
            ->map(fn ($rows) => $rows->pluck('total', 'status')->map(fn ($v) => (int) $v));

        $topEvents = (clone $base)->select('event', DB::raw('count(*) as total'))
            ->groupBy('event')->orderByDesc('total')->limit(10)->pluck('total', 'event')->map(fn ($v) => (int) $v);

        $sms       = $byChannel->get('sms', collect());
        $smsTotal  = $sms->sum();

        return response()->json([
            'days'          => $days,
            'by_channel'    => $byChannel,
            'top_events'    => $topEvents,
            'sms_sent'      => (int) ($sms['sent'] ?? 0),
            'sms_failed'    => (int) ($sms['failed'] ?? 0),
            'sms_fail_rate' => $smsTotal ? round(($sms['failed'] ?? 0) / $smsTotal * 100, 1) : 0,
        ]);
    }

    /** Relancer un SMS en échec. */
    public function retry(Request $request, NotificationDelivery $delivery)
    {
        abort_unless($this->scoped($request)->whereKey($delivery->id)->exists(), 404);
        abort_unless($delivery->channel === NotificationDelivery::CHANNEL_SMS && $delivery->status === NotificationDelivery::STATUS_FAILED, 409,
            'Seul un SMS en échec peut être relancé.');

        $this->notifier->retry($delivery);

        activity()->causedBy($request->user())->performedOn($delivery)->log('SMS relancé');

        return response()->json(['uuid' => $delivery->uuid, 'status' => $delivery->fresh()->status]);
    }

    private function scoped(Request $request)
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            return NotificationDelivery::query();
        }

        $allowed = $this->access->organizationIdsWithAbility($user, 'audit.view');
        abort_if(empty($allowed), 403);

        return NotificationDelivery::query()->whereIn('organization_id', $allowed);
    }
}
