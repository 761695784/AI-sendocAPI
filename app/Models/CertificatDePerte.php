<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CertificatDePerte extends Model
{
    use SoftDeletes;
    protected $guarded = [];

    public function declarationDePerte() // ← camelCase cohérent
    {
        return $this->belongsTo(DeclarationDePerte::class, 'declaration_de_perte_id');
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = (string) Str::uuid();
        });
    }

}
