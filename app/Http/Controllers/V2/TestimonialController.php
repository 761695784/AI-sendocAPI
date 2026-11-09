<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use App\Services\Testimonials\TestimonialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Témoignages des usagers.
 *  - public : liste des témoignages validés, dépôt d'un témoignage (compte facultatif) ;
 *  - super admin : modération (valider, refuser, corriger, supprimer).
 */
class TestimonialController extends Controller
{
    public function __construct(private TestimonialService $testimonials) {}

    /** Témoignages publiés (page d'accueil). */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min(24, (int) $request->query('limit', 6)));
        $published = Testimonial::published();

        return response()->json([
            'data'  => (clone $published)->latest('published_at')->limit($limit)->get()->map->toPublic(),
            'stats' => [
                'count'          => (clone $published)->count(),
                'average_rating' => ($avg = (clone $published)->whereNotNull('rating')->avg('rating')) ? round((float) $avg, 1) : null,
            ],
        ]);
    }

    /** Dépôt d'un témoignage : en attente de validation. Le jeton, s'il est fourni, identifie l'auteur. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'required|string|min:2|max:60',
            'city'       => 'required|string|min:2|max:80',
            'role'       => ['required', Rule::in(Testimonial::ROLES)],
            'rating'     => 'nullable|integer|between:1,5',
            'body'       => 'required|string|min:20|max:500',
            'consent'    => 'accepted',
            'contact'    => 'nullable|string|max:120',
            'locale'     => 'nullable|in:fr,en,pt',
            'website'    => 'nullable|string|max:0', // champ piège invisible : rempli = robot
        ], [
            'consent.accepted' => 'Cochez la case pour accepter la publication de votre témoignage.',
            'body.min'         => 'Votre témoignage doit faire au moins :min caractères.',
            'website.max'      => 'Envoi refusé.',
        ]);

        $testimonial = $this->testimonials->submit($data, Auth::guard('api')->user());

        return response()->json([
            'message' => 'Merci ! Votre témoignage sera publié après relecture par l\'équipe DocTrack.',
            'uuid'    => $testimonial->uuid,
            'status'  => $testimonial->status,
        ], 201);
    }

    /** File de modération (super admin). */
    public function moderation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'   => 'nullable|in:pending,approved,rejected',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $page = Testimonial::with(['user:id,FirstName,LastName,email', 'declaration:id,reference', 'moderator:id,FirstName,LastName'])
            ->where('status', $data['status'] ?? Testimonial::STATUS_PENDING)
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data'   => collect($page->items())->map->toModeration(),
            'counts' => Testimonial::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'meta'   => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Valider, refuser ou corriger (fautes, prénom, ville) un témoignage. */
    public function update(Request $request, Testimonial $testimonial): JsonResponse
    {
        $data = $request->validate([
            'status'          => 'nullable|in:pending,approved,rejected',
            'first_name'      => 'nullable|string|min:2|max:60',
            'city'            => 'nullable|string|min:2|max:80',
            'body'            => 'nullable|string|min:20|max:500',
            'rating'          => 'nullable|integer|between:1,5',
            'moderation_note' => 'nullable|string|max:255',
        ]);

        return response()->json($this->testimonials->moderate($testimonial, $request->user(), $data)->toModeration());
    }

    public function destroy(Testimonial $testimonial): JsonResponse
    {
        activity()->causedBy(Auth::user())->withProperties(['uuid' => $testimonial->uuid])->log('Témoignage supprimé');
        $testimonial->delete();

        return response()->json(['message' => 'Témoignage supprimé.']);
    }
}
