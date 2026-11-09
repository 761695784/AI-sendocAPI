<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Témoignage d'un usager. Publié seulement après validation (status = approved).
 * Seuls le prénom, la ville, la note et le texte sont rendus publics.
 */
class Testimonial extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const ROLES = ['owner', 'finder', 'other'];

    protected $guarded = ['id', 'uuid'];

    protected $hidden = ['contact_encrypted'];

    protected $casts = [
        'contact_encrypted' => 'encrypted',
        'verified'          => 'boolean',
        'rating'            => 'integer',
        'consent_at'        => 'datetime',
        'moderated_at'      => 'datetime',
        'published_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Testimonial $t) => $t->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_APPROVED)->whereNotNull('published_at');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function declaration()
    {
        return $this->belongsTo(DeclarationDePerte::class, 'declaration_de_perte_id');
    }

    public function moderator()
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /** Ce que voit le public. */
    public function toPublic(): array
    {
        return [
            'uuid'         => $this->uuid,
            'first_name'   => $this->first_name,
            'city'         => $this->city,
            'role'         => $this->role,
            'rating'       => $this->rating,
            'body'         => $this->body,
            'verified'     => $this->verified,
            'locale'       => $this->locale,
            'published_at' => $this->published_at?->toDateString(),
        ];
    }

    /** Ce que voit le super admin qui modère. */
    public function toModeration(): array
    {
        return $this->toPublic() + [
            'status'          => $this->status,
            'contact'         => $this->contact_encrypted,
            'account'         => $this->user ? [
                'name'  => trim($this->user->FirstName . ' ' . $this->user->LastName),
                'email' => $this->user->email,
            ] : null,
            'declaration'     => $this->declaration?->reference,
            'moderation_note' => $this->moderation_note,
            'moderated_at'    => $this->moderated_at?->toIso8601String(),
            'moderator'       => $this->moderator ? trim($this->moderator->FirstName . ' ' . $this->moderator->LastName) : null,
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
