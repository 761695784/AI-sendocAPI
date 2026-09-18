<?php

namespace App\Services;

use App\Models\RapportGenere;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;


class RapportService
{
    public function __construct(
        private StatistiqueService  $statistiqueService,
        private AnalyseReglesService $analyseService,
    ) {}

    // =========================================================================
    // POINT D'ENTRÉE PRINCIPAL
    // =========================================================================

    /**
     * Génère un rapport complet (stats + analyse + PDF) et le persiste en base.
     *
     * @param  int       $annee
     * @param  int|null  $mois   null = rapport annuel
     * @param  bool      $sauvegarde  false = aperçu sans sauvegarder
     * @return array  ['rapport' => RapportGenere, 'pdf_path' => string]
     */
    public function generer(int $annee, ?int $mois = null, bool $sauvegarde = true): array
    {
        // ── 1. Collecter les statistiques ─────────────────────────────────────
        $stats = $this->statistiqueService->getStatsCompletes($annee, $mois);

        // ── 2. Générer l'analyse basée sur règles ────────────────────────────
        $analyse = $this->analyseService->analyser($stats);

        // ── 3. Construire le payload complet pour la vue Blade ────────────────
        $payload = [
            'stats'   => $stats,
            'analyse' => $analyse,
            'meta'    => $this->buildMeta($annee, $mois),
        ];

        // ── 4. Générer le PDF ─────────────────────────────────────────────────
        $pdf = Pdf::loadView('rapports.national', $payload)
            ->setPaper('A4', 'portrait')
            ->setOptions([
                'dpi'                       => 150,
                'defaultFont'               => 'DejaVu Sans',
                'isRemoteEnabled'           => false,
                'isHtml5ParserEnabled'      => true,
                'isFontSubsettingEnabled'   => true,
            ]);

        // ── 5. Sauvegarder ou retourner directement ───────────────────────────
        if (!$sauvegarde) {
            // Mode aperçu : on ne sauvegarde pas en base
            return [
                'rapport'  => null,
                'pdf'      => $pdf,
                'payload'  => $payload,
            ];
        }

        $pdfPath  = $this->buildPdfPath($annee, $mois);
        $pdfBytes = $pdf->output();

        Storage::disk('public')->put($pdfPath, $pdfBytes);

        // ── 6. Persister le rapport en base ───────────────────────────────────
        // Supprimer l'éventuel rapport existant pour la même période
        RapportGenere::where('annee', $annee)
            ->where('mois', $mois)
            ->delete();

        $rapport = RapportGenere::create([
            'uuid'         => (string) Str::uuid(),
            'annee'        => $annee,
            'mois'         => $mois,
            'periode_label'=> $stats['periode'],
            'pdf_path'     => $pdfPath,
            'stats_json'   => $stats,
            'analyse_json' => $analyse,
            'genere_par'   => auth()->id(),
        ]);

        return [
            'rapport'  => $rapport,
            'pdf'      => $pdf,
            'payload'  => $payload,
        ];
    }

    // =========================================================================
    // ACCÈS AUX DONNÉES SANS GÉNÉRER LE PDF
    // (utile pour l'endpoint JSON du dashboard Angular)
    // =========================================================================

    public function getStatsEtAnalyse(int $annee, ?int $mois = null): array
    {
        $stats   = $this->statistiqueService->getStatsCompletes($annee, $mois);
        $analyse = $this->analyseService->analyser($stats);

        return [
            'stats'   => $stats,
            'analyse' => $analyse,
            'meta'    => $this->buildMeta($annee, $mois),
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function buildMeta(int $annee, ?int $mois): array
    {
        return [
            'numero_rapport'  => $this->genererNumeroRapport($annee, $mois),
            'genere_le'       => now()->format('d/m/Y à H:i'),
            'plateforme'      => 'DocTrack',
            'pays'            => 'République du Sénégal',
            'version'         => '1.0',
            'type'            => $mois ? 'Rapport Mensuel' : 'Rapport Annuel',
        ];
    }

    private function buildPdfPath(int $annee, ?int $mois): string
    {
        $slug = $mois
            ? "rapports/rapport_{$annee}_" . str_pad($mois, 2, '0', STR_PAD_LEFT) . '.pdf'
            : "rapports/rapport_{$annee}_annuel.pdf";

        return $slug;
    }

    private function genererNumeroRapport(int $annee, ?int $mois): string
    {
        $suffix = $mois
            ? str_pad($mois, 2, '0', STR_PAD_LEFT) . "/{$annee}"
            : "AN/{$annee}";

        return "DT-RPT-{$suffix}";
    }
}
