<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RapportGenere extends Model
{
    use HasFactory;

    protected $table = 'rapports_generes';

    protected $guarded = [];

    protected $casts = [
        'stats_json'   => 'array',
        'analyse_json' => 'array',
        'mois'         => 'integer',
        'annee'        => 'integer',
    ];

    // ── Relations ──────────────────────────────────────────────────────────────

    public function generateurRapport()
    {
        return $this->belongsTo(User::class, 'genere_par');
    }

    // ── Accesseurs ─────────────────────────────────────────────────────────────

    /**
     * URL publique du PDF
     */
    public function getPdfUrlAttribute(): ?string
    {
        if (!$this->pdf_path) {
            return null;
        }

        return Storage::disk('public')->url($this->pdf_path);
    }

    /**
     * Le PDF existe-t-il bien sur le disque ?
     */
    public function getPdfExisteAttribute(): bool
    {
        if (!$this->pdf_path) {
            return false;
        }

        return Storage::disk('public')->exists($this->pdf_path);
    }

    // ── Boot ───────────────────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });

        // Supprime le fichier PDF lors de la suppression du modèle
        static::deleting(function ($model) {
            if ($model->pdf_path && Storage::disk('public')->exists($model->pdf_path)) {
                Storage::disk('public')->delete($model->pdf_path);
            }
        });
    }
}
