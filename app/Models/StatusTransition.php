<?php

namespace App\Models;

use App\Http\Middleware\ResolveActiveOrganization;
use Illuminate\Database\Eloquent\Model;

class StatusTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['from', 'to', 'user_id', 'organization_id', 'reason'];

    public function transitionable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Enregistre un changement de statut (auteur et structure active par défaut).
     */
    public static function record(Model $model, ?string $from, string $to, ?string $reason = null, ?User $user = null, ?int $organizationId = null): self
    {
        return $model->morphMany(self::class, 'transitionable')->create([
            'from'            => $from,
            'to'              => $to,
            'user_id'         => $user?->id ?? auth('api')->id(),
            'organization_id' => $organizationId ?? ResolveActiveOrganization::current()?->id,
            'reason'          => $reason,
        ]);
    }
}
