<?php

namespace App\Services\Notifications;

use App\Jobs\SendSmsJob;
use App\Models\InboxMessage;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Point de passage unique des notifications DocTrack.
 * Chaque envoi est tracé dans notification_deliveries ; un échec
 * (opérateur SMS, serveur mail) ne bloque jamais l'action en cours.
 */
class Notifier
{
    /**
     * SMS à partir d'un modèle de config/notifications.php.
     *
     * @param array{recipient?: Model, subject?: Model, organization_id?: int|null, dedupe?: string|null} $context
     */
    public function sms(string $template, ?string $phone, array $params = [], array $context = []): ?NotificationDelivery
    {
        $text = $this->render($this->template('sms', $template), $params);
        if ($text === null) {
            Log::error('Modèle de SMS inconnu', ['template' => $template]);

            return null;
        }

        $delivery = $this->record(NotificationDelivery::CHANNEL_SMS, $template, $context, [
            'destination_masked' => self::maskPhone($phone),
            'status'             => $phone ? NotificationDelivery::STATUS_PENDING : NotificationDelivery::STATUS_SKIPPED,
            'error'              => $phone ? null : 'Aucun numéro',
        ], $text);

        if ($delivery && $phone) {
            SendSmsJob::dispatch($phone, $text, $delivery->id);
        }

        return $delivery;
    }

    /**
     * SMS envoyé tout de suite (le résultat conditionne la réponse à l'utilisateur).
     */
    public function smsNow(string $template, ?string $phone, array $params = [], array $context = []): bool
    {
        $text = $this->render($this->template('sms', $template), $params);
        if ($text === null) {
            return false;
        }

        $delivery = $this->record(NotificationDelivery::CHANNEL_SMS, $template, $context, [
            'destination_masked' => self::maskPhone($phone),
            'status'             => $phone ? NotificationDelivery::STATUS_PENDING : NotificationDelivery::STATUS_SKIPPED,
            'error'              => $phone ? null : 'Aucun numéro',
        ], $text);

        if (! $phone || ! $delivery) {
            return false;
        }

        (new SendSmsJob($phone, $text, $delivery->id))->handle(app(SmsService::class));

        return $delivery->fresh()->status === NotificationDelivery::STATUS_SENT;
    }

    /** Email (classe Notification Laravel) avec trace de l'envoi. */
    public function mail(string $event, ?User $user, Notification $notification, array $context = []): ?NotificationDelivery
    {
        $delivery = $this->record(NotificationDelivery::CHANNEL_EMAIL, $event, $context + ['recipient' => $user], [
            'destination_masked' => self::maskEmail($user?->email),
            'status'             => $user?->email ? NotificationDelivery::STATUS_PENDING : NotificationDelivery::STATUS_SKIPPED,
            'error'              => $user?->email ? null : 'Aucune adresse email',
        ]);

        if (! $delivery || ! $user?->email) {
            return $delivery;
        }

        try {
            $delivery->increment('attempts');
            $user->notify($notification);
            $delivery->markSent();
        } catch (\Throwable $e) {
            $delivery->markFailed($e->getMessage());
            Log::warning('Email non envoyé', ['event' => $event, 'error' => $e->getMessage()]);
        }

        return $delivery;
    }

    /** Email à une adresse (ex. adresse de la structure) à partir d'un modèle. */
    public function mailTo(string $template, ?string $email, array $params = [], array $context = []): ?NotificationDelivery
    {
        $tpl = $this->template('mail', $template);
        $delivery = $this->record(NotificationDelivery::CHANNEL_EMAIL, $template, $context, [
            'destination_masked' => self::maskEmail($email),
            'status'             => $email ? NotificationDelivery::STATUS_PENDING : NotificationDelivery::STATUS_SKIPPED,
            'error'              => $email ? null : 'Aucune adresse email',
        ]);

        if (! $delivery || ! $email || ! $tpl) {
            return $delivery;
        }

        try {
            $delivery->increment('attempts');
            \Illuminate\Support\Facades\Notification::route('mail', $email)->notify(new TemplatedMailNotification(
                $this->render($tpl['subject'], $params),
                array_map(fn ($l) => $this->render($l, $params), $tpl['lines']),
            ));
            $delivery->markSent();
        } catch (\Throwable $e) {
            $delivery->markFailed($e->getMessage());
        }

        return $delivery;
    }

    /**
     * Message dans la boîte de réception d'une structure (ou d'une personne).
     */
    public function inbox(string $template, Organization|User $to, array $params = [], array $context = [], ?string $link = null, bool $priority = false): ?InboxMessage
    {
        $tpl = $this->template('inbox', $template);
        if (! $tpl) {
            Log::error('Modèle de boîte de réception inconnu', ['template' => $template]);

            return null;
        }

        $orgId    = $to instanceof Organization ? $to->id : ($context['organization_id'] ?? null);
        $delivery = $this->record(NotificationDelivery::CHANNEL_INBOX, $template, $context + ['recipient' => $to, 'organization_id' => $orgId], [
            'destination_masked' => $to instanceof Organization ? $to->name : trim($to->FirstName . ' ' . $to->LastName),
            'status'             => NotificationDelivery::STATUS_SENT,
            'sent_at'            => now(),
        ]);
        if (! $delivery) {
            return null; // déjà envoyé (dédoublonnage)
        }

        $subject = $context['subject'] ?? null;

        return InboxMessage::create([
            'organization_id' => $to instanceof Organization ? $to->id : null,
            'user_id'         => $to instanceof User ? $to->id : null,
            'event'           => $template,
            'title'           => $this->render($tpl['title'], $params),
            'body'            => $this->render($tpl['body'], $params),
            'link'            => $link,
            'subject_type'    => $subject?->getMorphClass(),
            'subject_id'      => $subject?->getKey(),
            'priority'        => $priority,
        ]);
    }

    /** Aux super administrateurs (dossiers qu'aucune structure ne peut prendre). */
    public function inboxSuperAdmins(string $template, array $params = [], array $context = [], ?string $link = null): void
    {
        User::role(User::ROLE_SUPER_ADMIN, 'api')->get()->each(function (User $admin) use ($template, $params, $context, $link) {
            $ctx = $context;
            if (isset($ctx['dedupe'])) {
                $ctx['dedupe'] .= ':u' . $admin->id;
            }
            $this->inbox($template, $admin, $params, $ctx, $link, true);
        });
    }

    /** Relance d'un SMS en échec. */
    public function retry(NotificationDelivery $delivery): void
    {
        $phone = $this->phoneForRetry($delivery);
        $delivery->forceFill(['status' => NotificationDelivery::STATUS_PENDING, 'error' => null])->save();
        SendSmsJob::dispatch($phone, (string) $delivery->payload_encrypted, $delivery->id);
    }

    /** Les clés contiennent des points (ex. match.confirmed.online) : lecture à plat. */
    public function template(string $channel, string $key): mixed
    {
        return config("notifications.{$channel}", [])[$key] ?? null;
    }

    public function render(?string $template, array $params): ?string
    {
        if ($template === null) {
            return null;
        }
        // Les clés les plus longues d'abord (":structure_phone" avant ":structure").
        uksort($params, fn ($a, $b) => strlen($b) <=> strlen($a));
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[':' . $key] = (string) $value;
        }

        return trim(preg_replace('/\s+/', ' ', strtr($template, $pairs)));
    }

    public static function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return $digits ? str_repeat('•', max(strlen($digits) - 4, 0)) . substr($digits, -4) : null;
    }

    public static function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1) . '•••@' . $domain;
    }

    private function record(string $channel, string $event, array $context, array $attributes, ?string $payload = null): ?NotificationDelivery
    {
        $recipient = $context['recipient'] ?? null;
        $subject   = $context['subject'] ?? null;

        $delivery = new NotificationDelivery($attributes + [
            'event'           => $event,
            'channel'         => $channel,
            'recipient_type'  => $recipient?->getMorphClass(),
            'recipient_id'    => $recipient?->getKey(),
            'subject_type'    => $subject?->getMorphClass(),
            'subject_id'      => $subject?->getKey(),
            'organization_id' => $context['organization_id'] ?? null,
            'dedupe_key'      => isset($context['dedupe']) ? $channel . ':' . $context['dedupe'] : null,
        ]);
        $delivery->payload_encrypted = $payload;

        try {
            $delivery->save();
        } catch (QueryException $e) {
            if ($delivery->dedupe_key && NotificationDelivery::where('dedupe_key', $delivery->dedupe_key)->exists()) {
                return null; // déjà envoyé
            }
            throw $e;
        }

        return $delivery;
    }

    private function phoneForRetry(NotificationDelivery $delivery): ?string
    {
        $recipient = $delivery->recipient;

        return match (true) {
            $recipient instanceof User                     => $recipient->Phone,
            $recipient instanceof \App\Models\CitizenProfile => $recipient->phone(),
            $recipient instanceof Organization             => $recipient->phone,
            $recipient instanceof \App\Models\CollectionRequest => $recipient->finderPhone(),
            default                                        => null,
        };
    }
}
