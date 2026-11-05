<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Demande de restitution par le propriétaire.
 *
 *  - channel « direct » (document publié par un citoyen) :
 *      accepted (numéros échangés dès la demande) → completed (le trouveur marque « récupéré »)
 *      ↘ declined (le trouveur préfère passer par une structure)
 *    Les anciennes demandes en double accord passent par pending → accepted.
 *  - channel « counter » (document conservé par une structure) :
 *      pending (structure prévenue) → completed (remise au guichet, décharge)
 */
class RestitutionRequest extends Model
{
    use LogsActivity;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const CHANNEL_DIRECT  = 'direct';
    public const CHANNEL_COUNTER = 'counter';

    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_ACCEPTED];

    protected $fillable = [
        'channel', 'organization_id', 'terms_accepted_at',
        'document_id', 'document_match_id', 'requester_user_id', 'finder_user_id', 'status',
        'expires_at', 'responded_at', 'contact_shared_at', 'decline_reason', 'completed_at',
    ];

    protected $casts = [
        'expires_at'        => 'datetime',
        'responded_at'      => 'datetime',
        'contact_shared_at' => 'datetime',
        'completed_at'      => 'datetime',
        'terms_accepted_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'decline_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(fn (RestitutionRequest $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function document()
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }

    public function match()
    {
        return $this->belongsTo(DocumentMatch::class, 'document_match_id');
    }

    /** Structure qui conserve le document (demande de retrait au guichet). */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function finder()
    {
        return $this->belongsTo(User::class, 'finder_user_id');
    }

    public function transitions()
    {
        return $this->morphMany(StatusTransition::class, 'transitionable');
    }
}
