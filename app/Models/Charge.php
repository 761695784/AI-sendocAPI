<?php

namespace App\Models;

use App\Support\ReferenceGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Frais dû pour une démarche (reçu FA-…).
 *   pending → paid | exempted | cancelled ;  waived_pilot = gratuit (phase pilote), valeur théorique conservée.
 */
class Charge extends Model
{
    use LogsActivity;

    public const STATUS_PENDING      = 'pending';
    public const STATUS_PAID         = 'paid';
    public const STATUS_EXEMPTED     = 'exempted';
    public const STATUS_WAIVED_PILOT = 'waived_pilot';
    public const STATUS_CANCELLED    = 'cancelled';

    public const SETTLED_STATUSES = [self::STATUS_PAID, self::STATUS_EXEMPTED, self::STATUS_WAIVED_PILOT];

    protected $guarded = ['id', 'uuid', 'reference'];

    protected $casts = [
        'settled_at'   => 'datetime',
        'fulfilled_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['reference', 'status', 'amount', 'exemption_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (Charge $c) {
            $c->uuid      ??= (string) Str::uuid();
            $c->reference ??= ReferenceGenerator::charge();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isSettled(): bool
    {
        return in_array($this->status, self::SETTLED_STATUSES, true);
    }

    public function service()
    {
        return $this->belongsTo(BillableService::class, 'billing_service_id');
    }

    public function priceRule()
    {
        return $this->belongsTo(PriceRule::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function payerUser()
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    public function payerProfile()
    {
        return $this->belongsTo(CitizenProfile::class, 'payer_citizen_profile_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function exemptor()
    {
        return $this->belongsTo(User::class, 'exempted_by');
    }
}
