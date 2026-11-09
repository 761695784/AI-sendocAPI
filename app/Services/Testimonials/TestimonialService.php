<?php

namespace App\Services\Testimonials;

use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\Testimonial;
use App\Models\User;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\Log;

/**
 * Témoignages : dépôt depuis le site, modération, invitation après une restitution.
 */
class TestimonialService
{
    public function __construct(private Notifier $notifier) {}

    /**
     * Nouveau témoignage (en attente de validation). Les numéros (pièce, téléphone)
     * et adresses e-mail écrits dans le texte sont masqués avant tout enregistrement.
     */
    public function submit(array $data, ?User $user): Testimonial
    {
        [$verified, $declaration] = $this->proof($user, $data['role']);

        $testimonial = Testimonial::create([
            'user_id'                 => $user?->id,
            'declaration_de_perte_id' => $declaration?->id,
            'first_name'              => self::clean($data['first_name']),
            'city'                    => self::clean($data['city']),
            'role'                    => $data['role'],
            'rating'                  => $data['rating'] ?? null,
            'body'                    => self::mask(self::clean($data['body'])),
            'contact_encrypted'       => $data['contact'] ?? null,
            'locale'                  => $data['locale'] ?? 'fr',
            'verified'                => $verified,
            'consent_at'              => now(),
            'status'                  => Testimonial::STATUS_PENDING,
        ]);

        $params = [
            'first_name' => $testimonial->first_name,
            'city'       => $testimonial->city,
            'rating'     => $testimonial->rating ? $testimonial->rating . '/5' : 'sans note',
            'body'       => $testimonial->body,
            'verified'   => $verified ? 'oui (restitution enregistrée dans DocTrack)' : 'non',
            'url'        => rtrim(config('doctrack.frontend_url'), '/') . '/admin/temoignages',
        ];
        $this->notifier->inboxSuperAdmins('testimonial.pending', $params, ['subject' => $testimonial], 'admin/temoignages');
        $this->notifier->mailTo('testimonial.submitted', config('doctrack.testimonials.notify_email'), $params, ['subject' => $testimonial]);

        activity()->causedBy($user)->performedOn($testimonial)->withProperties(['role' => $testimonial->role])->log('Témoignage reçu');

        return $testimonial;
    }

    /** Validation, refus ou correction par un super admin. */
    public function moderate(Testimonial $testimonial, User $admin, array $data): Testimonial
    {
        $changes = array_filter([
            'first_name' => isset($data['first_name']) ? self::clean($data['first_name']) : null,
            'city'       => isset($data['city']) ? self::clean($data['city']) : null,
            'body'       => isset($data['body']) ? self::mask(self::clean($data['body'])) : null,
            'rating'     => $data['rating'] ?? null,
        ], fn ($v) => $v !== null);

        if (isset($data['status'])) {
            $changes['status']       = $data['status'];
            $changes['moderated_by'] = $admin->id;
            $changes['moderated_at'] = now();
            $changes['published_at'] = $data['status'] === Testimonial::STATUS_APPROVED
                ? ($testimonial->published_at ?? now())
                : null;
        }
        if (array_key_exists('moderation_note', $data)) {
            $changes['moderation_note'] = $data['moderation_note'];
        }

        $testimonial->fill($changes)->save();

        activity()->causedBy($admin)->performedOn($testimonial)
            ->withProperties(['status' => $testimonial->status])->log('Témoignage modéré');

        return $testimonial->fresh(['user', 'declaration', 'moderator']);
    }

    /**
     * Après une restitution : invitation à raconter son expérience (SMS + e-mail), une seule fois
     * par déclaration. Ne bloque jamais la restitution en cas d'erreur.
     */
    public function inviteAfterRestitution(DeclarationDePerte $declaration): void
    {
        if (! config('doctrack.testimonials.invite_after_restitution')) {
            return;
        }
        try {
            $declaration->loadMissing(['user', 'citizenProfile']);
            $phone = $declaration->user?->Phone ?: $declaration->citizenProfile?->phone();
            $email = $declaration->user?->email ?: $declaration->citizenProfile?->email;
            $params = [
                'first_name' => $declaration->user?->FirstName ?: ($declaration->citizenProfile?->first_name ?: ''),
                'url'        => rtrim(config('doctrack.frontend_url'), '/') . '/temoigner',
            ];
            $context = ['subject' => $declaration, 'recipient' => $declaration->user, 'dedupe' => 'testimonial.invite:' . $declaration->id];

            if ($phone) {
                $this->notifier->sms('testimonial.invite', $phone, $params, $context);
            }
            if ($email) {
                $this->notifier->mailTo('testimonial.invite', $email, $params, $context);
            }
        } catch (\Throwable $e) {
            Log::warning('Invitation à témoigner non envoyée', ['declaration' => $declaration->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Badge « restitution vérifiée » : l'auteur connecté a vraiment récupéré un document
     * (déclaration restituée) ou rendu un document (publication restituée) dans DocTrack.
     *
     * @return array{0: bool, 1: ?DeclarationDePerte}
     */
    private function proof(?User $user, string $role): array
    {
        if (! $user) {
            return [false, null];
        }
        $declaration = DeclarationDePerte::where('user_id', $user->id)
            ->where('status', DeclarationDePerte::STATUS_RESTITUTED)->latest('id')->first();
        if ($role === 'owner' || ($role === 'other' && $declaration)) {
            return [(bool) $declaration, $declaration];
        }
        $returned = Document::where('user_id', $user->id)
            ->where(fn ($q) => $q->where('statut', 'récupéré')->orWhere('lifecycle_status', Document::LIFECYCLE_RESTITUTED))
            ->exists();

        return [$returned, null];
    }

    /** Espaces superflus et balises retirés (le texte est toujours affiché comme du texte). */
    public static function clean(string $text): string
    {
        return trim(preg_replace('/[ \t]+/u', ' ', strip_tags($text)));
    }

    /**
     * Masque ce qui ne doit jamais être publié : adresses e-mail, numéros de téléphone,
     * numéros de pièce (6 chiffres ou plus, espaces, points ou tirets compris).
     */
    public static function mask(string $text): string
    {
        $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u', '•••', $text);

        return preg_replace_callback('/\+?\d[\d \.\-]{4,}\d/u', function ($m) {
            return preg_match_all('/\d/', $m[0]) >= 6 ? '•••' : $m[0];
        }, $text);
    }
}
