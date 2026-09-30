<?php

namespace App\Models;

use App\Support\ReferenceGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Rapport d'activité V2 (PDF + Excel) d'un périmètre sur une période. */
class ActivityReport extends Model
{
    protected $guarded = ['id', 'uuid', 'reference'];

    protected $casts = [
        'data'        => 'array',
        'analysis'    => 'array',
        'period_from' => 'date',
        'period_to'   => 'date',
        'automatic'   => 'boolean',
        'narrative'   => 'array',
        'narrative_attempts'    => 'array',
        'narrative_reviewed_at' => 'datetime',
        'delivered_at'          => 'datetime',
    ];

    public const NARRATIVE_NONE     = 'none';
    public const NARRATIVE_PENDING  = 'pending';
    public const NARRATIVE_APPROVED = 'approved';
    public const NARRATIVE_REJECTED = 'rejected';
    public const NARRATIVE_FAILED   = 'failed';

    /** Texte rédigé par l'IA publiable dans le rapport. */
    public function publishedNarrative(): ?array
    {
        return $this->narrative_status === self::NARRATIVE_APPROVED ? $this->narrative : null;
    }

    protected static function booted(): void
    {
        static::creating(function (ActivityReport $r) {
            $r->uuid      ??= (string) Str::uuid();
            $r->reference ??= ReferenceGenerator::report();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
