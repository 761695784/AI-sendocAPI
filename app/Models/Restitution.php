<?php

namespace App\Models;

use App\Support\ReferenceGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Restitution officielle d'un document au guichet (décharge RS-…).
 */
class Restitution extends Model
{
    use LogsActivity;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VOIDED    = 'voided';

    public const RECIPIENT_OWNER = 'owner';
    public const RECIPIENT_PROXY = 'proxy';

    public const ID_TYPES = ['cni', 'passeport', 'permis', 'carte_consulaire', 'carte_militaire', 'extrait_naissance', 'autre'];

    public const ID_TYPE_LABELS = [
        'cni'               => 'Carte nationale d\'identité',
        'passeport'         => 'Passeport',
        'permis'            => 'Permis de conduire',
        'carte_consulaire'  => 'Carte consulaire',
        'carte_militaire'   => 'Carte militaire',
        'extrait_naissance' => 'Extrait de naissance',
        'autre'             => 'Autre pièce',
    ];

    protected $guarded = ['id', 'uuid'];

    protected $hidden = ['owner_id_hash', 'proxy_id_hash', 'owner_phone_encrypted', 'proxy_phone_encrypted'];

    protected $casts = [
        'checks'                  => 'array',
        'owner_birth_date'        => 'date',
        'name_similarity'         => 'float',
        'handed_over_at'          => 'datetime',
        'signed_scan_uploaded_at' => 'datetime',
        'voided_at'               => 'datetime',
        'owner_phone_encrypted'   => 'encrypted',
        'proxy_phone_encrypted'   => 'encrypted',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['reference', 'status', 'void_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(function (Restitution $r) {
            $r->uuid              ??= (string) Str::uuid();
            $r->reference         ??= ReferenceGenerator::restitution();
            $r->verification_code ??= ReferenceGenerator::verificationCode();
            $r->status            ??= self::STATUS_COMPLETED;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    public function isProxy(): bool
    {
        return $this->recipient_type === self::RECIPIENT_PROXY;
    }

    public function verificationUrl(): string
    {
        return rtrim(config('doctrack.verification_url'), '/') . '/' . $this->verification_code;
    }

    public function formattedVerificationCode(): string
    {
        return ReferenceGenerator::formatVerificationCode((string) $this->verification_code);
    }

    public static function idTypeLabel(?string $type): ?string
    {
        return $type ? (self::ID_TYPE_LABELS[$type] ?? $type) : null;
    }

    public function document()
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function match()
    {
        return $this->belongsTo(DocumentMatch::class, 'document_match_id');
    }

    public function declaration()
    {
        return $this->belongsTo(DeclarationDePerte::class, 'declaration_de_perte_id')->withTrashed();
    }

    public function ownerProfile()
    {
        return $this->belongsTo(CitizenProfile::class, 'owner_citizen_profile_id');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function transitions()
    {
        return $this->morphMany(StatusTransition::class, 'transitionable');
    }
}
