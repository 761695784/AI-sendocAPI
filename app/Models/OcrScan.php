<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OcrScan extends Model
{
    public const PURPOSE_FOUND_DOCUMENT = 'found_document';

    public const STATUS_USED    = 'used';
    public const STATUS_EXPIRED = 'expired';

    protected $guarded = [];

    protected $hidden = ['result', 'image_path', 'masked_path'];

    protected $casts = [
        'result'      => 'encrypted:array',
        'corrections' => 'array',
        'expires_at'  => 'datetime',
        'used_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (OcrScan $s) => $s->uuid ??= (string) Str::uuid());
        static::deleting(fn (OcrScan $s) => $s->deleteImages());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->status !== self::STATUS_EXPIRED
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && $this->image_path && Storage::disk('local')->exists($this->image_path);
    }

    public function field(string $name): ?string
    {
        return $this->result['fields'][$name]['value'] ?? null;
    }

    public function deleteImages(): void
    {
        foreach (['image_path', 'masked_path'] as $attr) {
            if ($this->{$attr}) {
                Storage::disk('local')->delete($this->{$attr});
            }
        }
    }
}
