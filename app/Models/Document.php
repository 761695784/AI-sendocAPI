<?php

namespace App\Models;

use Illuminate\Support\Str;
use App\Services\Matching\NameNormalizer;
use App\Support\ReferenceGenerator;
use App\Support\SensitiveData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;


/**
 * Document trouvé.
 *
 * - found_channel "citizen" : publié par un citoyen (V1)
 * - found_channel "counter" : déposé dans une structure (garde physique)
 *
 * Cycle de vie (lifecycle_status) :
 *   published → in_custody → matched → restitution_pending → restituted
 *   (ou archived / destroyed). La colonne V1 "statut" reste synchronisée.
 */
class Document extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, LogsActivity, InteractsWithMedia;

    public const CHANNEL_CITIZEN = 'citizen';
    public const CHANNEL_COUNTER = 'counter';
    public const CHANNEL_QR_SCAN = 'qr_scan'; // signalé par scan du QR collé sur le document

    public const LIFECYCLE_PUBLISHED           = 'published';
    public const LIFECYCLE_IN_CUSTODY          = 'in_custody';
    public const LIFECYCLE_MATCHED             = 'matched';
    public const LIFECYCLE_RESTITUTION_PENDING = 'restitution_pending';
    public const LIFECYCLE_RESTITUTED          = 'restituted';
    public const LIFECYCLE_ARCHIVED            = 'archived';
    public const LIFECYCLE_DESTROYED           = 'destroyed';

    /** Statuts dans lesquels un document peut encore trouver son propriétaire. */
    public const MATCHABLE = [self::LIFECYCLE_PUBLISHED, self::LIFECYCLE_IN_CUSTODY];

    protected $guarded = [];

    protected $hidden = ['doc_number_hash', 'doc_number_encrypted'];

    protected $casts = [
        'found_at'             => 'date',
        'birth_date_in_doc'    => 'date',
        'custody_since'        => 'datetime',
        'contact_consent_at'   => 'datetime',
        'found_latitude'       => 'float',
        'found_longitude'      => 'float',
        'doc_number_encrypted' => 'encrypted',
    ];

    // ── Activity Log ────────────────────────────────
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['uuid', 'reference', 'OwnerFirstName', 'OwnerLastName', 'statut', 'lifecycle_status', 'custody_organization_id', 'Location'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function documentType() {
        return $this->belongsTo(DocumentType::class);
    }

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function comments() {
        return $this->hasMany(Commentaire::class);
    }

    public function custodyOrganization()
    {
        return $this->belongsTo(Organization::class, 'custody_organization_id');
    }

    public function depositedBy()
    {
        return $this->belongsTo(User::class, 'deposited_by_user_id');
    }

    public function finder()
    {
        return $this->belongsTo(CitizenProfile::class, 'finder_citizen_profile_id');
    }

    public function territory()
    {
        return $this->belongsTo(Territory::class);
    }

    public function matches()
    {
        return $this->hasMany(DocumentMatch::class);
    }

    public function collectionRequests()
    {
        return $this->hasMany(CollectionRequest::class);
    }

    public function tag()
    {
        return $this->belongsTo(DocumentTag::class, 'document_tag_id');
    }

    public function restitutionRequests()
    {
        return $this->hasMany(RestitutionRequest::class);
    }

    public function openCollection(): ?CollectionRequest
    {
        return $this->collectionRequests()->open()->latest('id')->first();
    }

    public function transitions()
    {
        return $this->morphMany(StatusTransition::class, 'transitionable');
    }

    public function isInCustody(): bool
    {
        return $this->custody_organization_id !== null;
    }

    public function docNumber(): ?string
    {
        return $this->doc_number_encrypted;
    }

    /** Change le cycle de vie, synchronise "statut" (V1) et trace la transition. */
    public function moveTo(string $lifecycle, ?string $reason = null, ?User $user = null): void
    {
        $from = $this->lifecycle_status;
        if ($from === $lifecycle) {
            return;
        }

        $this->lifecycle_status = $lifecycle;
        $this->statut = $lifecycle === self::LIFECYCLE_RESTITUTED ? 'récupéré' : 'non récupéré';
        $this->save();

        StatusTransition::record($this, $from, $lifecycle, $reason, $user);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Document $model) {
            $model->uuid      ??= (string) Str::uuid();
            $model->reference ??= ReferenceGenerator::foundDocument();
        });

        // Numéro haché/chiffré + clés phonétiques pour le moteur de correspondance.
        static::saving(function (Document $model) {
            if ($model->isDirty('DocIdentification') && $model->DocIdentification) {
                $normalized = SensitiveData::normalizeDocNumber($model->DocIdentification);
                $model->doc_number_hash      = SensitiveData::hash($normalized);
                $model->doc_number_encrypted = $normalized;
                $model->doc_number_last4     = $normalized ? substr($normalized, -4) : null;
            }
            if ($model->isDirty(['OwnerFirstName', 'OwnerLastName']) || ! $model->last_name_key) {
                $names = app(NameNormalizer::class);
                $model->first_name_key = $names->key($model->OwnerFirstName);
                $model->last_name_key  = $names->key($model->OwnerLastName);
            }
        });

        static::deleted(function (Document $model) {
            app(\App\Services\Matching\MatchReviewService::class)
                ->supersedeForDocument($model, auth('api')->user(), 'Document supprimé');
            app(\App\Services\Collections\CollectionService::class)
                ->cancelForDocument($model, auth('api')->user(), 'Publication supprimée');
            app(\App\Services\Restitution\DirectRestitutionService::class)
                ->cancelForDocument($model, auth('api')->user(), 'Publication supprimée');
        });

        static::restored(fn (Document $model) => \App\Jobs\MatchDocumentJob::dispatch($model->id));
    }

    // ── MediaLibrary : collections ───────────────────
    // L'original (photo nette d'une pièce d'identité) est stocké sur le disque
    // PRIVÉ "local". Seules les versions floutées sont écrites sur le disque
    // public. L'original n'est servi que via une URL signée (publicateur/Admin).
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('document_image')
             ->singleFile()
             ->useDisk('local')
             ->storeConversionsOnDisk('public')
             ->acceptsMimeTypes([
                 'image/jpeg',
                 'image/png',
                 'image/webp',
             ]);
    }

    // ── MediaLibrary : conversions (toutes floutées) ─
    public function registerMediaConversions(?Media $media = null): void
    {
        // Vignette floutée — listes
        $this->addMediaConversion('thumb')
             ->width(400)
             ->height(300)
             ->blur(8)
             ->format('webp')
             ->performOnCollections('document_image')
             ->nonQueued();

        // Version floutée — détail public
        $this->addMediaConversion('blurred')
             ->blur(8)
             ->width(800)
             ->format('webp')
             ->performOnCollections('document_image')
             ->nonQueued();
    }
}
