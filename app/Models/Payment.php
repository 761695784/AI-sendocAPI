<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Tentative de paiement d'un frais (espèces au guichet, Wave…). */
class Payment extends Model
{
    public const PROVIDER_CASH = 'cash';
    public const PROVIDER_WAVE = 'wave';

    public const STATUS_INITIATED = 'initiated';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_EXPIRED   = 'expired';

    protected $guarded = ['id', 'uuid'];

    protected $casts = ['payload' => 'array', 'confirmed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (Payment $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function charge()
    {
        return $this->belongsTo(Charge::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
