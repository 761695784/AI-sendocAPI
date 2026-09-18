<?php

namespace App\Http\Controllers;

use App\Models\RapportGenere;
use App\Services\RapportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class RapportController extends Controller
{
    public function __construct(private RapportService $rapportService) {}

    // =========================================================================
    // LISTER LES RAPPORTS GÉNÉRÉS (Admin)
    // GET /api/rapports
    // =========================================================================

    public function index()
    {
        $this->checkAdmin();

        $rapports = RapportGenere::with('generateurRapport')
            ->orderByDesc('annee')
            ->orderByDesc('mois')
            ->get()
            ->map(fn($r) => [
                'uuid'          => $r->uuid,
                'periode_label' => $r->periode_label,
                'annee'         => $r->annee,
                'mois'          => $r->mois,
                'type'          => $r->mois ? 'Mensuel' : 'Annuel',
                'pdf_url'       => $r->pdf_url,
                'pdf_existe'    => $r->pdf_existe,
                'genere_par'    => $r->generateurRapport?->FirstName . ' ' . $r->generateurRapport?->LastName,
                'genere_le'     => $r->created_at->format('d/m/Y à H:i'),
            ]);

        return response()->json([
            'success'  => true,
            'rapports' => $rapports,
        ]);
    }

    // =========================================================================
    // GÉNÉRER UN NOUVEAU RAPPORT + PDF (Admin)
    // POST /api/rapports/generer
    // Body: { "annee": 2026, "mois": 3 }  ← mois optionnel
    // =========================================================================

    public function generer(Request $request)
    {
        $this->checkAdmin();

        $validated = $request->validate([
            'annee' => ['required', 'integer', 'min:2020', 'max:' . now()->year],
            'mois'  => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $annee = (int) $validated['annee'];
        $mois  = isset($validated['mois']) ? (int) $validated['mois'] : null;

        // Vérification : on ne peut pas générer un rapport pour le futur
        if ($mois && now()->year === $annee && $mois > now()->month) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de générer un rapport pour une période future.',
            ], 422);
        }

        try {
            $result = $this->rapportService->generer($annee, $mois, sauvegarde: true);

            return response()->json([
                'success'       => true,
                'message'       => "Rapport {$result['rapport']->periode_label} généré avec succès.",
                'rapport'       => [
                    'uuid'          => $result['rapport']->uuid,
                    'periode_label' => $result['rapport']->periode_label,
                    'pdf_url'       => $result['rapport']->pdf_url,
                    'genere_le'     => $result['rapport']->created_at->format('d/m/Y à H:i'),
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du rapport : ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // APERÇU PDF INLINE (Admin)
    // GET /api/rapports/apercu?annee=2026&mois=3
    // =========================================================================

    public function apercu(Request $request)
    {
        $this->checkAdmin();

        $validated = $request->validate([
            'annee' => ['required', 'integer', 'min:2020', 'max:' . now()->year],
            'mois'  => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $annee = (int) $validated['annee'];
        $mois  = isset($validated['mois']) ? (int) $validated['mois'] : null;

        try {
            $result = $this->rapportService->generer($annee, $mois, sauvegarde: false);

            return $result['pdf']->stream("apercu-rapport-{$annee}.pdf");

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération de l\'aperçu : ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // TÉLÉCHARGER UN RAPPORT EXISTANT (Admin)
    // GET /api/rapports/{uuid}/telecharger
    // =========================================================================

    public function telecharger(string $uuid)
    {
        $this->checkAdmin();

        $rapport = RapportGenere::where('uuid', $uuid)->firstOrFail();

        if (!$rapport->pdf_existe) {
            return response()->json([
                'success' => false,
                'message' => 'Le fichier PDF de ce rapport est introuvable. Veuillez le régénérer.',
            ], 404);
        }

        $path = Storage::disk('public')->path($rapport->pdf_path);
        $nom  = "DocTrack-Rapport-{$rapport->periode_label}.pdf";

        return response()->download($path, $nom, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    // =========================================================================
    // VOIR UN RAPPORT EXISTANT EN PDF INLINE (Admin)
    // GET /api/rapports/{uuid}/voir
    // =========================================================================

    public function voir(string $uuid)
    {
        $this->checkAdmin();

        $rapport = RapportGenere::where('uuid', $uuid)->firstOrFail();

        if (!$rapport->pdf_existe) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier PDF introuvable. Veuillez régénérer ce rapport.',
            ], 404);
        }

        $path = Storage::disk('public')->path($rapport->pdf_path);

        return response()->file($path, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="rapport-' . $uuid . '.pdf"',
        ]);
    }

    // =========================================================================
    // DONNÉES JSON POUR LE DASHBOARD ANGULAR (Admin)
    // GET /api/rapports/stats?annee=2026&mois=3
    // =========================================================================

    public function stats(Request $request)
    {
        $this->checkAdmin();

        $validated = $request->validate([
            'annee' => ['required', 'integer', 'min:2020', 'max:' . now()->year],
            'mois'  => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $annee = (int) $validated['annee'];
        $mois  = isset($validated['mois']) ? (int) $validated['mois'] : null;

        $data = $this->rapportService->getStatsEtAnalyse($annee, $mois);

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    // =========================================================================
    // SUPPRIMER UN RAPPORT (Admin)
    // DELETE /api/rapports/{uuid}
    // =========================================================================

    public function destroy(string $uuid)
    {
        $this->checkAdmin();

        $rapport = RapportGenere::where('uuid', $uuid)->firstOrFail();
        $label   = $rapport->periode_label;

        $rapport->delete(); // Le boot() du modèle supprime aussi le fichier PDF

        return response()->json([
            'success' => true,
            'message' => "Rapport \"{$label}\" supprimé avec succès.",
        ]);
    }

    // =========================================================================
    // HELPER
    // =========================================================================

    private function checkAdmin(): void
    {
        $user = Auth::user();

        if (!$user || !$user->hasRole('Admin')) {
            abort(403, 'Accès réservé aux administrateurs.');
        }
    }
}
