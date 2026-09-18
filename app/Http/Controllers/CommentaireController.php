<?php
namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentaireRequest;
use App\Mail\NewCommentNotification;
use App\Models\Commentaire;
use App\Models\Document;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class CommentaireController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Récupérer et retourner tous les commentaires avec leurs utilisateurs et documents associés
        $commentaires = Commentaire::with(['user', 'document'])->get();
        return response()->json($commentaires);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCommentaireRequest $request)
    {
        // Vérifier si l'utilisateur est authentifié
        if (! Auth::check()) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être authentifié pour commenter.',
            ], 401);
        }

        // Récupérer l'utilisateur authentifié
        $user = Auth::user();

        // Créer un nouveau commentaire
        $commentaire = Commentaire::create([
            'contenu'     => $request->contenu,
            'user_id'     => $user->id,
            'document_id' => $request->document_id,
        ]);

        // Récupérer la publication associée
        $document = Document::find($request->document_id);

        // Récupérer l'auteur de la publication
        $auteur = $document->user;

        // Envoyer un email à l'auteur
        Mail::to($auteur->email)->send(new NewCommentNotification($document, $commentaire, $auteur));

        // Retourner une réponse JSON avec le commentaire créé
        return response()->json([
            'success'     => true,
            'message'     => 'Commentaire créé avec succès.',
            'commentaire' => $commentaire,
        ], 201);
    }
    /**
     * Display the specified resource.
     */
    public function show(Commentaire $commentaire)
    {
        // Récupérer le commentaire spécifique avec l'utilisateur et le document
        $commentaire = Commentaire::with(['user', 'document'])->findOrFail($commentaire->id);

        // Retourner le commentaire en JSON
        return response()->json($commentaire);
    }
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Commentaire $commentaire)
    {
        // Vérifier si l'utilisateur est authentifié et propriétaire du commentaire
        if (Auth::id() !== $commentaire->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à supprimer ce commentaire.',
            ], 403);
        }

        // Supprimer le commentaire
        $commentaire->delete();

        return response()->json([
            'success' => true,
            'message' => 'Commentaire supprimé avec succès.',
        ]);
    }

    public function getCommentairesByDocument($uuid)
    {
        // Trouver le document par son UUID
        $document = Document::where('uuid', $uuid)->firstOrFail();

        // Récupérer les commentaires via la relation
        $commentaires = $document->comments()->with('user')->get();

        // Retourner les commentaires au format JSON
        return response()->json($commentaires);
    }

}
