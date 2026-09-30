<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Numéro supplémentaire d'un document trouvé (empreinte seulement). */
class DocumentNumber extends Model
{
    protected $guarded = [];

    protected $hidden = ['hash'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
