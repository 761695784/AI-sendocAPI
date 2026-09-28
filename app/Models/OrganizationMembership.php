<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Affectation d'un agent à une organisation, avec son rôle dans celle-ci.
 */
class OrganizationMembership extends Model
{
    use HasFactory, LogsActivity;

    public const ROLE_INSTITUTION_ADMIN  = 'institution_admin';
    public const ROLE_STRUCTURE_MANAGER  = 'structure_manager';
    public const ROLE_AGENT              = 'agent';

    /** Du plus élevé au plus bas. */
    public const ROLES = [self::ROLE_INSTITUTION_ADMIN, self::ROLE_STRUCTURE_MANAGER, self::ROLE_AGENT];

    protected $fillable = [
        'user_id', 'organization_id', 'role', 'matricule', 'position',
        'is_primary', 'starts_at', 'ends_at', 'created_by',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'organization_id', 'role', 'matricule', 'ends_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (OrganizationMembership $m) {
            $m->uuid ??= (string) Str::uuid();
            $m->starts_at ??= now();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    /** Rang du rôle : 0 = le plus élevé. */
    public static function rank(string $role): int
    {
        $index = array_search($role, self::ROLES, true);

        return $index === false ? PHP_INT_MAX : $index;
    }
}
