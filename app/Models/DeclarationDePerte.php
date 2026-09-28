<?php

namespace App\Models;

use App\Support\ReferenceGenerator;
use App\Support\SensitiveData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Déclaration de perte.
 *
 * - channel "online"  : faite par le citoyen lui-même (V1) → récépissé DocTrack
 * - channel "counter" : enregistrée au guichet par un agent → certificat officiel
 *
 * Les colonnes V1 (Title, FirstNameInDoc, LastNameInDoc, DocIdentification,
 * document_type_id) reprennent la première pièce ; les pièces détaillées sont
 * dans declaration_items.
 */
class DeclarationDePerte extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    public const CHANNEL_ONLINE  = 'online';
    public const CHANNEL_COUNTER = 'counter';

    public const STATUS_REGISTERED              = 'registered';
    public const STATUS_MATCHED                 = 'matched';
    public const STATUS_RESTITUTION_IN_PROGRESS = 'restitution_in_progress';
    public const STATUS_RESTITUTED              = 'restituted';
    public const STATUS_CLOSED                  = 'closed';
    public const STATUS_CANCELLED               = 'cancelled';
    public const STATUS_EXPIRED                 = 'expired';

    protected $guarded = [];

    protected $hidden = ['doc_number_hash', 'doc_number_encrypted'];

    protected $casts = [
        'lost_at'              => 'date',
        'birth_date_in_doc'    => 'date',
        'closed_at'            => 'datetime',
        'doc_number_encrypted' => 'encrypted',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['uuid', 'reference', 'status', 'channel', 'organization_id', 'Title', 'FirstNameInDoc', 'LastNameInDoc'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (DeclarationDePerte $model) {
            $model->uuid      ??= (string) Str::uuid();
            $model->reference ??= ReferenceGenerator::declaration();
            $model->channel   ??= self::CHANNEL_ONLINE;
            $model->status    ??= self::STATUS_REGISTERED;
        });

        // V1 : le numéro saisi en clair est aussi haché/chiffré pour le matching.
        static::saving(function (DeclarationDePerte $model) {
            if ($model->isDirty('DocIdentification') && $model->DocIdentification) {
                $normalized = SensitiveData::normalizeDocNumber($model->DocIdentification);
                $model->doc_number_hash      = SensitiveData::hash($normalized);
                $model->doc_number_encrypted = $normalized;
            }
        });

        // Soft delete → soft delete du certificat ; restauration → restauration.
        static::deleting(function ($declaration) {
            if (! $declaration->isForceDeleting()) {
                $declaration->certificat?->delete();
            }
            app(\App\Services\Matching\MatchReviewService::class)
                ->supersedeForDeclaration($declaration, auth('api')->user(), 'Déclaration supprimée');
        });

        static::restoring(function ($declaration) {
            $declaration->certificat()->withTrashed()->restore();
        });

        static::restored(fn ($declaration) => \App\Jobs\MatchDeclarationJob::dispatch($declaration->id));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isCounter(): bool
    {
        return $this->channel === self::CHANNEL_COUNTER;
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_REGISTERED, self::STATUS_MATCHED], true);
    }

    // ── Relations ─────────────────────────────────────

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function citizenProfile()
    {
        return $this->belongsTo(CitizenProfile::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function territory()
    {
        return $this->belongsTo(Territory::class);
    }

    public function items()
    {
        return $this->hasMany(DeclarationItem::class);
    }

    public function matches()
    {
        return $this->hasMany(DocumentMatch::class);
    }

    public function charges()
    {
        return $this->morphMany(Charge::class, 'subject');
    }

    public function transitions()
    {
        return $this->morphMany(StatusTransition::class, 'transitionable');
    }

    /**
     * Déclaration V1 (une seule pièce, colonnes FirstNameInDoc…) : crée la
     * pièce correspondante pour que le moteur de correspondance la voie.
     */
    public function ensureLegacyItem(): ?DeclarationItem
    {
        if ($this->items()->exists() || ! $this->document_type_id || ! $this->LastNameInDoc) {
            return $this->items()->orderBy('id')->first();
        }

        $item = new DeclarationItem([
            'declaration_de_perte_id' => $this->id,
            'document_type_id'        => $this->document_type_id,
            'first_name'              => (string) $this->FirstNameInDoc,
            'last_name'               => $this->LastNameInDoc,
            'birth_date_in_doc'       => $this->birth_date_in_doc ?? null,
        ]);
        $item->setDocNumber($this->doc_number_encrypted ?: $this->DocIdentification);
        $item->save();

        return $item;
    }

    /** Change le statut et trace la transition. */
    public function moveTo(string $status, ?string $reason = null, ?User $user = null): void
    {
        $from = $this->status;
        if ($from === $status) {
            return;
        }
        $this->status = $status;
        $this->save();

        StatusTransition::record($this, $from, $status, $reason, $user);
    }

    public function certificat()
    {
        return $this->hasOne(CertificatDePerte::class, 'declaration_de_perte_id')->withTrashed();
    }
}
