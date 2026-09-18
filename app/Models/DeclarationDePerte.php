<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Eloquent\Model;
use App\Mail\DocumentPublishedNotification;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class DeclarationDePerte extends Model
{
    use HasFactory;    use SoftDeletes; use LogsActivity;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['uuid', 'Title', 'FirstNameInDoc', 'LastNameInDoc'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
    
    public function documentType() {
        return $this->belongsTo(DocumentType::class, 'document_type_id'); // Assurez-vous que 'document_type_id' est la bonne clé étrangère
    }

    public function user() {
        return $this->belongsTo(User::class);
    }

    private function sendNotificationEmail($user, $document)
    {
        // Récupérer le numéro de téléPhone du propriétaire du document
        $Phone = $document->user->Phone; // Assurez-vous que 'Phone' est bien le nom de votre colonne
        $documentUrl = route('documents.show', $document->id); // Créer l'URL pour afficher le document

        // Envoyer le mail avec le document et le numéro de téléPhone
        Mail::to($user->email)->send(new DocumentPublishedNotification($document, $Phone, $documentUrl));
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });

            // ← Soft delete → soft delete le certificat aussi
        static::deleting(function ($declaration) {
        if (!$declaration->isForceDeleting()) {
            // Soft delete — on garde le PDF, juste on soft delete le certificat
            $declaration->certificat?->delete();
        }
        });

            // ← Restauration → restaure le certificat aussi
        static::restoring(function ($declaration) {
            $declaration->certificat()->withTrashed()->restore();
        });
    }

    public function certificat()
    {
        return $this->hasOne(CertificatDePerte::class, 'declaration_de_perte_id')
        ->withTrashed();
    }
}
