<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;


class Document extends Model implements HasMedia
{
    use HasFactory; use SoftDeletes; use LogsActivity;use InteractsWithMedia;
    protected $guarded = [];


    // ── Activity Log ────────────────────────────────
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['uuid', 'OwnerFirstName', 'OwnerLastName', 'statut', 'Location'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
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

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
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
