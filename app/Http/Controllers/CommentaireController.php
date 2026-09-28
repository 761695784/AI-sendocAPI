<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentaireRequest;
use App\Http\Resources\CommentaireResource;
use App\Mail\NewCommentNotification;
use App\Models\Commentaire;
use App\Models\Document;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CommentaireController extends Controller
{
    /**
     * Admin : tous les commentaires. Citoyen : uniquement les siens.
     */
    public function index()
    {
        $user = Auth::user();

        $commentaires = Commentaire::with('user')
            ->when(! $user->hasRole('Admin'), fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->get();

        return response()->json(CommentaireResource::collection($commentaires)->resolve());
    }

    public function store(StoreCommentaireRequest $request)
    {
        $user     = Auth::user();
        $document = Document::findOrFail($request->document_id);

        $commentaire = Commentaire::create([
            'contenu'     => $request->contenu,
            'user_id'     => $user->id,
            'document_id' => $document->id,
        ]);

        // Pas d'email si l'auteur commente sa propre publication.
        if ($document->user && $document->user_id !== $user->id) {
            try {
                Mail::to($document->user->email)->send(new NewCommentNotification($document, $commentaire, $document->user));
            } catch (\Exception $e) {
                Log::error('Email nouveau commentaire : ' . $e->getMessage());
            }
        }

        return response()->json([
            'success'     => true,
            'message'     => 'Commentaire créé avec succès.',
            'commentaire' => new CommentaireResource($commentaire->load('user')),
        ], 201);
    }

    public function show($id)
    {
        $commentaire = $this->find($id);

        return response()->json(new CommentaireResource($commentaire->load('user')));
    }

    public function destroy($id)
    {
        $commentaire = $this->find($id);
        $user        = Auth::user();

        if ($user->id !== $commentaire->user_id && ! $user->hasRole('Admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à supprimer ce commentaire.',
            ], 403);
        }

        $commentaire->delete();

        return response()->json([
            'success' => true,
            'message' => 'Commentaire supprimé avec succès.',
        ]);
    }

    public function getCommentairesByDocument($uuid)
    {
        $document     = Document::where('uuid', $uuid)->firstOrFail();
        $commentaires = $document->comments()->with('user')->get();

        return response()->json(CommentaireResource::collection($commentaires)->resolve());
    }

    /** Accepte l'uuid (recommandé) ou l'id numérique (compatibilité V1). */
    private function find($id): Commentaire
    {
        return Commentaire::where(ctype_digit((string) $id) ? 'id' : 'uuid', $id)->firstOrFail();
    }
}
