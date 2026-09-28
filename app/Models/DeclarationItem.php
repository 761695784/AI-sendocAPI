<?php

namespace App\Models;

use App\Support\SensitiveData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Une pièce perdue au sein d'une déclaration.
 */
class DeclarationItem extends Model
{
    use HasFactory;

    public const STATUS_LOST       = 'lost';
    public const STATUS_FOUND      = 'found';
    public const STATUS_RESTITUTED = 'restituted';

    protected $fillable = [
        'declaration_de_perte_id', 'document_type_id', 'first_name', 'last_name',
        'birth_date_in_doc', 'status',
    ];

    protected $hidden = ['doc_number_hash', 'doc_number_encrypted'];

    protected $casts = [
        'birth_date_in_doc'    => 'date',
        'doc_number_encrypted' => 'encrypted',
    ];

    protected static function booted(): void
    {
        static::creating(fn (DeclarationItem $i) => $i->uuid ??= (string) Str::uuid());
    }

    public function setDocNumber(?string $number): static
    {
        $normalized = SensitiveData::normalizeDocNumber($number);

        $this->doc_number_encrypted = $normalized;
        $this->doc_number_hash      = SensitiveData::hash($normalized);
        $this->doc_number_last4     = $normalized ? substr($normalized, -4) : null;

        return $this;
    }

    public function docNumber(): ?string
    {
        return $this->doc_number_encrypted;
    }

    public function declaration()
    {
        return $this->belongsTo(DeclarationDePerte::class, 'declaration_de_perte_id');
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }
}
