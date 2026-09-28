<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\PrivateFiles;
use Illuminate\Support\Facades\URL;
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
     * URL signée et temporaire du PDF (fichier stocké sur disque privé).
     */
    public function getPdfUrlAttribute(): ?string
    {
        if (!$this->pdf_path) {
            return null;
        }

        return URL::temporarySignedRoute('files.rapport', now()->addMinutes(30), ['uuid' => $this->uuid]);
    }

    /**
     * Le PDF existe-t-il bien sur le disque ?
     */
    public function getPdfExisteAttribute(): bool
    {
        if (!$this->pdf_path) {
            return false;
        }

        return PrivateFiles::exists($this->pdf_path);
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
            PrivateFiles::delete($model->pdf_path);
        });
    }
}
