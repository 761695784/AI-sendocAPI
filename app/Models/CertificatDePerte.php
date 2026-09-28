<?php

namespace App\Models;

use App\Support\ReferenceGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CertificatDePerte extends Model
{
    use SoftDeletes;

    public const KIND_RECEIPT  = 'receipt';   // récépissé DocTrack (en ligne)
    public const KIND_OFFICIAL = 'official';  // émis par une structure habilitée

    protected $guarded = [];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];

    public function declarationDePerte()
    {
        return $this->belongsTo(DeclarationDePerte::class, 'declaration_de_perte_id')->withTrashed();
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function issuingOrganization()
    {
        return $this->belongsTo(Organization::class, 'issued_by_organization_id');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function isOfficial(): bool
    {
        return $this->kind === self::KIND_OFFICIAL;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function verificationUrl(): string
    {
        return rtrim(config('doctrack.verification_url'), '/') . '/' . $this->verification_code;
    }

    public function formattedVerificationCode(): string
    {
        return ReferenceGenerator::formatVerificationCode((string) $this->verification_code);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid              ??= (string) Str::uuid();
            $model->kind              ??= self::KIND_RECEIPT;
            $model->verification_code ??= ReferenceGenerator::verificationCode();
        });
    }
}
