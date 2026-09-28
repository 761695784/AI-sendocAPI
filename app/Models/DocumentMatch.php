<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Correspondance pièce perdue ↔ document trouvé (table "matches").
 * ("Match" est un mot réservé en PHP 8.)
 */
class DocumentMatch extends Model
{
    use LogsActivity;

    protected $table = 'matches';

    public const STATUS_PROPOSED       = 'proposed';        // possible : visible agent, pas de notification
    public const STATUS_PENDING_REVIEW = 'pending_review';  // probable : à valider par un agent
    public const STATUS_CONFIRMED      = 'confirmed';       // validée (agent ou numéro identique)
    public const STATUS_REJECTED       = 'rejected';        // écartée : ne sera jamais reproposée
    public const STATUS_SUPERSEDED     = 'superseded';      // devenue sans objet

    public const OPEN_STATUSES  = [self::STATUS_PROPOSED, self::STATUS_PENDING_REVIEW];
    public const FINAL_STATUSES = [self::STATUS_CONFIRMED, self::STATUS_REJECTED, self::STATUS_SUPERSEDED];

    protected $fillable = [
        'declaration_de_perte_id', 'declaration_item_id', 'document_id', 'organization_id',
        'engine', 'engine_version', 'score', 'score_details', 'status', 'number_match',
        'reviewed_by', 'reviewed_at', 'review_note', 'notified_at',
    ];

    protected $casts = [
        'score_details' => 'array',
        'number_match'  => 'boolean',
        'reviewed_at'   => 'datetime',
        'notified_at'   => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'score', 'reviewed_by', 'review_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        static::creating(fn (DocumentMatch $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function declaration()
    {
        return $this->belongsTo(DeclarationDePerte::class, 'declaration_de_perte_id');
    }

    public function item()
    {
        return $this->belongsTo(DeclarationItem::class, 'declaration_item_id');
    }

    public function document()
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function transitions()
    {
        return $this->morphMany(StatusTransition::class, 'transitionable');
    }
}
