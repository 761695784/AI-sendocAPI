<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AssistantConversation extends Model
{
    public const STATUS_ACTIVE     = 'active';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_HANDED_OFF = 'handed_off';
    public const STATUS_ABANDONED  = 'abandoned';

    protected $guarded = [];

    protected $casts = [
        'slots'            => 'array',
        'last_activity_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AssistantConversation $c) {
            $c->uuid ??= (string) Str::uuid();
            $c->last_activity_at ??= now();
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

    public function messages()
    {
        return $this->hasMany(AssistantMessage::class)->orderBy('id');
    }

    public function slot(string $key, mixed $default = null): mixed
    {
        return ($this->slots ?? [])[$key] ?? $default;
    }

    public function setSlot(string $key, mixed $value): void
    {
        $slots       = $this->slots ?? [];
        $slots[$key] = $value;
        $this->slots = $slots;
    }

    /** Démarre un parcours (efface les réponses du parcours précédent). */
    public function startFlow(?string $flow, ?string $step = null): void
    {
        $this->flow  = $flow;
        $this->step  = $step;
        $this->slots = [];
    }

    public function isExpired(): bool
    {
        return $this->last_activity_at
            && $this->last_activity_at->lt(now()->subMinutes((int) config('assistant.conversation_ttl_minutes', 30)));
    }
}
