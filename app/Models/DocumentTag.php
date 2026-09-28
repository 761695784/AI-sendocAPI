<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * QR code collé sur un document précis. Le QR ne contient que le jeton ;
 * scanner mène à une page DocTrack qui ne montre aucune donnée personnelle.
 */
class DocumentTag extends Model
{
    public const STATUS_ACTIVE          = 'active';
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_REVOKED         = 'revoked';
    public const STATUS_REPLACED        = 'replaced';

    protected $fillable = ['user_id', 'document_type_id', 'label', 'doc_number_last4', 'status', 'replaces_id', 'activated_at', 'revoked_at'];

    protected $hidden = ['token'];

    protected $casts = ['last_scanned_at' => 'datetime', 'activated_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (DocumentTag $t) {
            $t->uuid  ??= (string) Str::uuid();
            // 32 caractères aléatoires (≈ 190 bits) : impossible à deviner.
            $t->token ??= Str::random(32);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scanUrl(): string
    {
        return rtrim(config('doctrack.frontend_url'), '/') . '/t/' . $this->token;
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function replaces()
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    public function scans()
    {
        return $this->hasMany(TagScan::class);
    }

    public function charges()
    {
        return $this->morphMany(Charge::class, 'subject');
    }
}
