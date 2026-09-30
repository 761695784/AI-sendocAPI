<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }
}
