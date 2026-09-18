<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $fillable = [
        'from', 'to', 'subject', 'body', 'publisher_user_id', 'requester_user_id', 'document_id', 'declarant_user_id',
    ];

    // Relation avec le modèle Document
    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    // Relation avec l'utilisateur demandeur (requester)
    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    // Relation avec le publicateur
    public function publisher()
    {
        return $this->belongsTo(User::class, 'publisher_user_id');
    }

    // Relation avec le déclarant
    public function declarant()
    {
        return $this->belongsTo(User::class, 'declarant_user_id');
    }
}



