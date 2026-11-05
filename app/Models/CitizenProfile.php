<?php

namespace App\Models;

use App\Support\SensitiveData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Personne physique (victime / déclarant), avec ou sans compte DocTrack.
 */
class CitizenProfile extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'birth_date', 'email',
        'created_by_user_id', 'created_by_organization_id',
    ];

    protected $hidden = ['phone_encrypted', 'phone_hash'];

    protected $casts = [
        'birth_date'      => 'date',
        'phone_encrypted' => 'encrypted',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['first_name', 'last_name', 'user_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(fn (CitizenProfile $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Écrit le téléphone chiffré + haché. */
    public function setPhone(?string $phone): static
    {
        $this->phone_encrypted = $phone ?: null;
        $this->phone_hash      = SensitiveData::hashPhone($phone);

        return $this;
    }

    public function phone(): ?string
    {
        return $this->phone_encrypted;
    }

    public function maskedPhone(): ?string
    {
        $phone = $this->phone();

        return $phone ? str_repeat('•', max(strlen($phone) - 4, 0)) . substr($phone, -4) : null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Structure où la fiche a été créée (guichet). */
    public function createdByOrganization()
    {
        return $this->belongsTo(Organization::class, 'created_by_organization_id');
    }

    public function declarations()
    {
        return $this->hasMany(DeclarationDePerte::class);
    }

    public function scopeWherePhone($query, string $phone)
    {
        return $query->where('phone_hash', SensitiveData::hashPhone($phone));
    }
}
