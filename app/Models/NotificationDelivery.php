<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Trace d'un envoi (SMS, email, boîte de réception).
 */
class NotificationDelivery extends Model
{
    public const CHANNEL_SMS   = 'sms';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_INBOX = 'inbox';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped'; // pas de destinataire joignable

    protected $fillable = [
        'event', 'channel', 'recipient_type', 'recipient_id', 'destination_masked', 'subject_type', 'subject_id',
        'organization_id', 'status', 'attempts', 'error', 'dedupe_key', 'sent_at',
    ];

    protected $hidden = ['payload_encrypted'];

    protected $casts = [
        'payload_encrypted' => 'encrypted',
        'sent_at'           => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (NotificationDelivery $d) => $d->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function recipient()
    {
        return $this->morphTo();
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function markSent(): void
    {
        $this->forceFill(['status' => self::STATUS_SENT, 'sent_at' => now(), 'error' => null])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['status' => self::STATUS_FAILED, 'error' => mb_substr($error, 0, 250)])->save();
    }

    /** Texte affiché dans le suivi : les numéros de téléphone sont masqués. */
    public function previewText(): ?string
    {
        $text = $this->payload_encrypted;

        return $text ? preg_replace_callback('/\+?\d[\d\s]{6,}\d/', fn ($m) => '•••' . substr(preg_replace('/\D/', '', $m[0]), -3), $text) : null;
    }
}
