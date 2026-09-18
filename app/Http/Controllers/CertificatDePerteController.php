<?php
namespace App\Http\Controllers;

use App\Models\CertificatDePerte;
use Illuminate\Support\Facades\Auth;

class CertificatDePerteController extends Controller
{
    /**
     * Tous les certificats — Admin uniquement
     */
    public function index()
    {
        $user = Auth::user();

        if (! $user->hasRole('Admin')) {
            return response()->json(['success' => false, 'message' => 'Accès non autorisé.'], 403);
        }

        $certificats = CertificatDePerte::with([
            'declarationDePerte.documentType',
            'declarationDePerte.user',
        ])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn($c) => $c->declarationDePerte !== null) // ← évite le null
            ->map(function ($certificat) {
                $declaration = $certificat->declarationDePerte;
                return [
                    'id'          => $certificat->id,
                    'uuid'        => $certificat->uuid,
                    'nom'         => $declaration->LastNameInDoc,
                    'prenom'      => $declaration->FirstNameInDoc,
                    'created_at'  => $certificat->created_at->toDateTimeString(),
                    'declaration' => [
                        'uuid'              => $declaration->uuid,
                        'Title'             => $declaration->Title,
                        'FirstNameInDoc'    => $declaration->FirstNameInDoc,
                        'LastNameInDoc'     => $declaration->LastNameInDoc,
                        'DocIdentification' => $declaration->DocIdentification,
                        'document_type'     => $declaration->documentType,
                        'user'              => $declaration->user,
                    ],
                ];
            })
            ->values(); // ← réindexe après filter

        return response()->json([
            'success'     => true,
            'certificats' => $certificats,
        ]);
    }

    /**
     * Mes certificats — Utilisateur connecté
     */
    public function mesCertificats()
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.',
            ], 401);
        }

        $certificats = CertificatDePerte::with([
            'declarationDePerte.documentType',
            'declarationDePerte.user',
        ])
            ->whereHas('declarationDePerte', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($certificat) {
                $declaration = $certificat->declarationDePerte;
                return [
                    'id'          => $certificat->id,
                    'uuid'        => $certificat->uuid,
                    'nom'         => $declaration->LastNameInDoc,
                    'prenom'      => $declaration->FirstNameInDoc,
                    'created_at'  => $certificat->created_at->toDateTimeString(),
                    'declaration' => [
                        'uuid'              => $declaration->uuid,
                        'Title'             => $declaration->Title,
                        'FirstNameInDoc'    => $declaration->FirstNameInDoc,
                        'LastNameInDoc'     => $declaration->LastNameInDoc,
                        'DocIdentification' => $declaration->DocIdentification,
                        'document_type'     => $declaration->documentType,
                        'user'              => $declaration->user,
                    ],
                ];
            });

        return response()->json([
            'success'     => true,
            'certificats' => $certificats,
        ]);
    }

    /**
     * Voir un certificat en PDF
     * CORRIGÉ : admin peut voir tous les certificats
     */
    public function voir($uuid)
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $certificat = CertificatDePerte::with('declarationDePerte')
            ->where('uuid', $uuid)
            ->firstOrFail();

        // Admin peut voir tous — user ne voit que les siens
        if (! $user->hasRole('Admin') &&
            $certificat->declarationDePerte->user_id !== $user->id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $path = storage_path('app/public/' . $certificat->pdf_path);

        if (! file_exists($path)) {
            return response()->json(['message' => 'Fichier introuvable'], 404);
        }

        return response()->file($path, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="certificat-' . $uuid . '.pdf"',
        ]);
    }

    /**
     * Télécharger un certificat
     * CORRIGÉ : admin peut télécharger tous les certificats
     */
    public function telecharger($uuid)
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.',
            ], 401);
        }

        // CORRIGÉ : cherche par uuid au lieu de findOrFail($id)
        $certificat = CertificatDePerte::with('declarationDePerte')
            ->where('uuid', $uuid)
            ->firstOrFail();

        // Admin peut télécharger tous — user seulement les siens
        if (! $user->hasRole('Admin') &&
            $certificat->declarationDePerte->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé à ce certificat.',
            ], 403);
        }

        $pdfPath = storage_path('app/public/' . $certificat->pdf_path);

        if (! file_exists($pdfPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier PDF introuvable.',
            ], 404);
        }

        return response()->download(
            $pdfPath,
            "certificat-perte-{$uuid}.pdf"
        );
    }
}
