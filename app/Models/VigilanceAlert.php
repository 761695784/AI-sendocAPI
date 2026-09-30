<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class VigilanceAlert extends Model
{
    public const LEVEL_INFO    = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_HIGH    = 'high';

    public const STATUS_OPEN      = 'open';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DISMISSED = 'dismissed';

    protected $guarded = [];

    protected $casts = [
        'details'     => 'array',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (VigilanceAlert $a) => $a->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeOpen($q)
    {
        return $q->where('status', self::STATUS_OPEN);
    }
}
