<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Nœud de l'arbre institutionnel.
 *
 * path  = "/<id ancêtre>/…/<id>/" (ex. "/1/7/") — calculé automatiquement.
 * depth = 0 pour une institution racine.
 */
class Organization extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'parent_id', 'organization_type_id', 'name', 'code', 'territory_id',
        'address', 'phone', 'email', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'parent_id', 'organization_type_id', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $org) {
            $org->uuid ??= (string) Str::uuid();
        });

        // Le chemin dépend de l'id : calculé juste après l'insertion.
        static::created(function (Organization $org) {
            $org->refreshPath();
        });

        static::updating(function (Organization $org) {
            if ($org->isDirty('parent_id')) {
                if ($org->parent_id && $org->parent && str_contains($org->parent->path, "/{$org->id}/")) {
                    throw new \InvalidArgumentException('Une organisation ne peut pas être rattachée à sa propre descendance.');
                }
            }
        });

        static::updated(function (Organization $org) {
            if ($org->wasChanged('parent_id')) {
                $oldPath = $org->path;
                $org->refreshPath();
                // Répercute le nouveau chemin sur toute la descendance.
                static::withTrashed()->where('path', 'like', $oldPath . '%')
                    ->where('id', '!=', $org->id)
                    ->orderBy('depth')
                    ->get()
                    ->each->refreshPath();
            }
        });
    }

    public function refreshPath(): void
    {
        $parent = $this->parent_id ? static::withTrashed()->find($this->parent_id) : null;
        $path   = ($parent ? $parent->path : '/') . $this->id . '/';
        $depth  = $parent ? $parent->depth + 1 : 0;

        if ($this->path !== $path || $this->depth !== $depth) {
            $this->forceFill(['path' => $path, 'depth' => $depth])->saveQuietly();
        }
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ── Relations ─────────────────────────────────────

    public function parent()
    {
        return $this->belongsTo(Organization::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Organization::class, 'parent_id');
    }

    public function type()
    {
        return $this->belongsTo(OrganizationType::class, 'organization_type_id');
    }

    public function territory()
    {
        return $this->belongsTo(Territory::class);
    }

    public function memberships()
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function activeMemberships()
    {
        return $this->memberships()->active();
    }

    // ── Arbre ─────────────────────────────────────────

    /** L'organisation elle-même et toute sa descendance. */
    public function scopeSubtreeOf(Builder $query, Organization $root): Builder
    {
        return $query->where('path', 'like', $root->path . '%');
    }

    /** Ids des ancêtres (du plus haut au parent direct). */
    public function ancestorIds(): array
    {
        $ids = array_values(array_map('intval', array_filter(explode('/', $this->path))));
        array_pop($ids); // retire l'organisation elle-même

        return $ids;
    }

    /** L'institution racine de cette organisation. */
    public function institution(): Organization
    {
        $rootId = $this->ancestorIds()[0] ?? $this->id;

        return $rootId === $this->id ? $this : static::withTrashed()->find($rootId);
    }

    public function isDescendantOf(Organization $other): bool
    {
        return $this->id !== $other->id && str_starts_with($this->path, $other->path);
    }

    public function isSameOrDescendantOf(Organization $other): bool
    {
        return str_starts_with($this->path, $other->path);
    }
}
