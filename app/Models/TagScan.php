<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Signalement d'un trouveur après scan d'un QR de document. */
class TagScan extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['finder_phone_encrypted', 'finder_phone_hash', 'ip_hash'];

    protected $casts = [
        'finder_phone_encrypted' => 'encrypted',
        'allow_owner_contact'    => 'boolean',
        'contact_consent'        => 'boolean',
        'latitude'               => 'float',
        'longitude'              => 'float',
    ];

    public function tag()
    {
        return $this->belongsTo(DocumentTag::class, 'document_tag_id');
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
