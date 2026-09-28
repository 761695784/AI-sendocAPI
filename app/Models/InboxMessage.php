<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Message de la boîte de réception de l'espace institutionnel.
 */
class InboxMessage extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'event', 'title', 'body', 'link', 'subject_type', 'subject_id', 'priority'];

    protected $casts = ['priority' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (InboxMessage $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function readers()
    {
        return $this->belongsToMany(User::class, 'inbox_message_reads')->withPivot('read_at');
    }

    /** Messages visibles par un utilisateur : les siens + ceux de ses structures. */
    public function scopeVisibleTo(Builder $q, User $user, array $organizationIds): Builder
    {
        return $q->where(fn ($q) => $q->where('user_id', $user->id)
            ->orWhere(fn ($q) => $q->whereNull('user_id')->whereIn('organization_id', $organizationIds)));
    }

    public function scopeUnreadBy(Builder $q, User $user): Builder
    {
        return $q->whereDoesntHave('readers', fn ($r) => $r->where('users.id', $user->id));
    }
}
