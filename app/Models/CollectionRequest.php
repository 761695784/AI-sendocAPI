<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Collecte d'un document publié en ligne par la structure la plus proche.
 *
 *  unassigned → assigned → contacted → planned → collected
 *                  └──────────┴───────────┴────→ cancelled
 */
class CollectionRequest extends Model
{
    use LogsActivity;

    public const STATUS_UNASSIGNED = 'unassigned'; // aucune structure trouvée : super_admin
    public const STATUS_ASSIGNED   = 'assigned';   // à appeler
    public const STATUS_CONTACTED  = 'contacted';  // trouveur joint
    public const STATUS_PLANNED    = 'planned';    // dépôt ou passage d'un agent prévu
    public const STATUS_COLLECTED  = 'collected';  // document sous la garde de la structure
    public const STATUS_CANCELLED  = 'cancelled';

    public const OPEN_STATUSES = [self::STATUS_UNASSIGNED, self::STATUS_ASSIGNED, self::STATUS_CONTACTED, self::STATUS_PLANNED];

    public const METHOD_DROP_OFF     = 'drop_off';     // le trouveur apporte le document
    public const METHOD_AGENT_PICKUP = 'agent_pickup'; // un agent va le chercher

    public const OUTCOME_REACHED      = 'reached';
    public const OUTCOME_NO_ANSWER    = 'no_answer';
    public const OUTCOME_WRONG_NUMBER = 'wrong_number';

    protected $fillable = [
        'document_id', 'organization_id', 'status', 'assigned_at', 'assignment_reason', 'distance_km',
        'declined_organization_ids', 'finder_user_id', 'finder_name', 'contact_attempts', 'last_contact_at',
        'last_contact_outcome', 'method', 'scheduled_at', 'meeting_place', 'pickup_agent_id',
        'collected_at', 'collected_by', 'closed_reason', 'owner_identified_at',
    ];

    protected $hidden = ['finder_phone_encrypted'];

    protected $casts = [
        'assigned_at'               => 'datetime',
        'last_contact_at'           => 'datetime',
        'scheduled_at'              => 'datetime',
        'collected_at'              => 'datetime',
        'owner_identified_at'       => 'datetime',
        'declined_organization_ids' => 'array',
        'distance_km'               => 'float',
        'finder_phone_encrypted'    => 'encrypted',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'organization_id', 'method', 'scheduled_at', 'pickup_agent_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(fn (CollectionRequest $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function finderPhone(): ?string
    {
        return $this->finder_phone_encrypted;
    }

    public function setFinderPhone(?string $phone): static
    {
        $this->finder_phone_encrypted = $phone ? preg_replace('/[^0-9+]/', '', $phone) : null;

        return $this;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function document()
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function finder()
    {
        return $this->belongsTo(User::class, 'finder_user_id');
    }

    public function pickupAgent()
    {
        return $this->belongsTo(User::class, 'pickup_agent_id');
    }

    public function collector()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function transitions()
    {
        return $this->morphMany(StatusTransition::class, 'transitionable');
    }
}
