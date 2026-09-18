<?php

namespace App\Services;

use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\EmailLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class StatistiqueService
{
    /**
     * Point d'entrée principal.
     * Retourne toutes les stats pour la période demandée.
     *
     * @param int       $annee  Année du rapport (ex: 2026)
     * @param int|null  $mois   Null = rapport annuel, sinon rapport mensuel
     */
    public function getStatsCompletes(int $annee, ?int $mois = null): array
    {
        return [
            'periode'               => $this->buildPeriodeLabel($annee, $mois),
            'annee'                 => $annee,
            'mois'                  => $mois,
            'genere_le'             => now()->format('d/m/Y à H:i'),

            // ── Déclarations de perte ──────────────────────────
            'declarations'          => $this->getStatsDeclarations($annee, $mois),

            // ── Publications (documents trouvés) ───────────────
            'publications'          => $this->getStatsPublications($annee, $mois),

            // ── Par type de document ───────────────────────────
            'par_type'              => $this->getStatsByType($annee, $mois),

            // ── Par région / localisation ──────────────────────
            'par_region'            => $this->getStatsByRegion($annee, $mois),

            // ── Évolution temporelle ───────────────────────────
            'evolution'             => $this->getEvolution($annee, $mois),

            // ── Restitutions ───────────────────────────────────
            'restitutions'          => $this->getStatsRestitutions($annee, $mois),

            // ── Utilisateurs ───────────────────────────────────
            'utilisateurs'          => $this->getStatsUtilisateurs($annee, $mois),

            // ── Comparaison avec période précédente ────────────
            'comparaison'           => $this->getComparaisonPeriodePrecedente($annee, $mois),
        ];
    }

    // =========================================================================
    // DÉCLARATIONS DE PERTE
    // =========================================================================

    private function getStatsDeclarations(int $annee, ?int $mois): array
    {
        $query = DeclarationDePerte::withTrashed()
            ->when($annee, fn($q) => $q->whereYear('created_at', $annee))
            ->when($mois,  fn($q) => $q->whereMonth('created_at', $mois));

        $total       = $query->count();
        $actives     = (clone $query)->whereNull('deleted_at')->count();
        $supprimees  = (clone $query)->whereNotNull('deleted_at')->count();

        return [
            'total'      => $total,
            'actives'    => $actives,
            'supprimees' => $supprimees,
        ];
    }

    // =========================================================================
    // PUBLICATIONS (documents trouvés publiés sur la plateforme)
    // =========================================================================

    private function getStatsPublications(int $annee, ?int $mois): array
    {
        $query = Document::withTrashed()
            ->when($annee, fn($q) => $q->whereYear('created_at', $annee))
            ->when($mois,  fn($q) => $q->whereMonth('created_at', $mois));

        $total         = $query->count();
        $recuperes     = (clone $query)->where('statut', 'récupéré')->count();
        $nonRecuperes  = (clone $query)->where('statut', 'non récupéré')->count();
        $supprimes     = (clone $query)->whereNotNull('deleted_at')->count();

        // Taux de restitution = documents récupérés / total publications
        $tauxRestitution = $total > 0
            ? round(($recuperes / $total) * 100, 1)
            : 0;

        return [
            'total'           => $total,
            'recuperes'       => $recuperes,
            'non_recuperes'   => $nonRecuperes,
            'supprimes'       => $supprimes,
            'taux_restitution'=> $tauxRestitution, // en %
        ];
    }

    // =========================================================================
    // PAR TYPE DE DOCUMENT
    // (CNI, Passeport, Permis de conduire, etc.)
    // =========================================================================

    private function getStatsByType(int $annee, ?int $mois): array
    {
        // Déclarations par type
        $declarationsParType = DeclarationDePerte::withTrashed()
            ->select('document_type_id', DB::raw('COUNT(*) as total'))
            ->when($annee, fn($q) => $q->whereYear('created_at', $annee))
            ->when($mois,  fn($q) => $q->whereMonth('created_at', $mois))
            ->groupBy('document_type_id')
            ->with('documentType')
            ->get()
            ->map(fn($item) => [
                'type'  => $item->documentType?->TypeName ?? 'Inconnu',
                'total' => $item->total,
            ]);

        // Publications par type
        $publicationsParType = Document::withTrashed()
            ->select('document_type_id', DB::raw('COUNT(*) as total'))
            ->when($annee, fn($q) => $q->whereYear('created_at', $annee))
            ->when($mois,  fn($q) => $q->whereMonth('created_at', $mois))
            ->groupBy('document_type_id')
            ->with('documentType')
            ->get()
            ->map(fn($item) => [
                'type'  => $item->documentType?->TypeName ?? 'Inconnu',
                'total' => $item->total,
            ]);

        return [
            'declarations' => $declarationsParType,
            'publications' => $publicationsParType,
        ];
    }

    // =========================================================================
    // PAR RÉGION / LOCALISATION
    // (basé sur le champ Location des publications)
    // =========================================================================

    private function getStatsByRegion(int $annee, ?int $mois): array
    {
        $regions = Document::withTrashed()
            ->select(
                'Location',
                DB::raw('COUNT(*) as total_publications'),
                DB::raw('SUM(statut = "récupéré") as recuperes'),
                DB::raw('SUM(statut = "non récupéré") as non_recuperes')
            )
            ->when($annee, fn($q) => $q->whereYear('created_at', $annee))
            ->when($mois,  fn($q) => $q->whereMonth('created_at', $mois))
            ->groupBy('Location')
            ->orderByDesc('total_publications')
            ->get()
            ->map(fn($item) => [
                'region'             => $item->Location,
                'total_publications' => $item->total_publications,
                'recuperes'          => (int) $item->recuperes,
                'non_recuperes'      => (int) $item->non_recuperes,
                'taux_restitution'   => $item->total_publications > 0
                    ? round(($item->recuperes / $item->total_publications) * 100, 1)
                    : 0,
            ]);

        // Zone la plus touchée
        $zoneLaPlusTouchee = $regions->first();

        // Zones critiques = taux de restitution < 20% ET au moins 2 publications
        $zonesCritiques = $regions->filter(
            fn($r) => $r['taux_restitution'] < 20 && $r['total_publications'] >= 2
        )->values();

        return [
            'liste'              => $regions,
            'zone_la_plus_touchee' => $zoneLaPlusTouchee,
            'zones_critiques'    => $zonesCritiques,
            'nombre_localites'   => $regions->count(),
        ];
    }

    // =========================================================================
    // ÉVOLUTION TEMPORELLE
    // =========================================================================

    private function getEvolution(int $annee, ?int $mois): array
    {
        if ($mois) {
            // Vue journalière pour un rapport mensuel
            return $this->getEvolutionJournaliere($annee, $mois);
        }

        // Vue mensuelle pour un rapport annuel
        return $this->getEvolutionMensuelle($annee);
    }

    private function getEvolutionMensuelle(int $annee): array
    {
        $declarations = DeclarationDePerte::withTrashed()
            ->selectRaw('MONTH(created_at) as periode, COUNT(*) as total')
            ->whereYear('created_at', $annee)
            ->groupBy('periode')
            ->orderBy('periode')
            ->pluck('total', 'periode')
            ->toArray();

        $publications = Document::withTrashed()
            ->selectRaw('MONTH(created_at) as periode, COUNT(*) as total')
            ->whereYear('created_at', $annee)
            ->groupBy('periode')
            ->orderBy('periode')
            ->pluck('total', 'periode')
            ->toArray();

        $labels = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars',
            4 => 'Avril',   5 => 'Mai',     6 => 'Juin',
            7 => 'Juillet', 8 => 'Août',    9 => 'Septembre',
            10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];

        $evolution = [];
        for ($m = 1; $m <= 12; $m++) {
            $evolution[] = [
                'label'        => $labels[$m],
                'declarations' => $declarations[$m] ?? 0,
                'publications' => $publications[$m] ?? 0,
            ];
        }

        return [
            'type'   => 'mensuelle',
            'donnees'=> $evolution,
        ];
    }

    private function getEvolutionJournaliere(int $annee, int $mois): array
    {
        $nbJours = Carbon::createFromDate($annee, $mois, 1)->daysInMonth;

        $declarations = DeclarationDePerte::withTrashed()
            ->selectRaw('DAY(created_at) as jour, COUNT(*) as total')
            ->whereYear('created_at', $annee)
            ->whereMonth('created_at', $mois)
            ->groupBy('jour')
            ->pluck('total', 'jour')
            ->toArray();

        $publications = Document::withTrashed()
            ->selectRaw('DAY(created_at) as jour, COUNT(*) as total')
            ->whereYear('created_at', $annee)
            ->whereMonth('created_at', $mois)
            ->groupBy('jour')
            ->pluck('total', 'jour')
            ->toArray();

        $evolution = [];
        for ($j = 1; $j <= $nbJours; $j++) {
            $evolution[] = [
                'label'        => $j,
                'declarations' => $declarations[$j] ?? 0,
                'publications' => $publications[$j] ?? 0,
            ];
        }

        return [
            'type'   => 'journaliere',
            'donnees'=> $evolution,
        ];
    }

    // =========================================================================
    // RESTITUTIONS
    // =========================================================================

    private function getStatsRestitutions(int $annee, ?int $mois): array
    {
        // Demandes de restitution = emails avec sujet "restitution"
        $demandesRestitution = EmailLog::where('subject', 'LIKE', '%restitution%')
            ->when($annee, fn($q) => $q->whereYear('created_at', $annee))
            ->when($mois,  fn($q) => $q->whereMonth('created_at', $mois))
            ->count();

        // Documents effectivement récupérés (statut changé)
        $documentsRecuperes = Document::withTrashed()
            ->where('statut', 'récupéré')
            ->when($annee, fn($q) => $q->whereYear('updated_at', $annee))
            ->when($mois,  fn($q) => $q->whereMonth('updated_at', $mois))
            ->count();

        return [
            'demandes_restitution' => $demandesRestitution,
            'documents_recuperes'  => $documentsRecuperes,
        ];
    }

    // =========================================================================
    // UTILISATEURS
    // =========================================================================

    private function getStatsUtilisateurs(int $annee, ?int $mois): array
    {
        $totalUtilisateurs = User::when($annee, fn($q) => $q->whereYear('created_at', $annee))
            ->when($mois, fn($q) => $q->whereMonth('created_at', $mois))
            ->count();

        // Utilisateurs actifs = ceux qui ont fait au moins 1 déclaration ou publication
        $utilisateursActifs = User::where(function ($q) use ($annee, $mois) {
            $q->whereHas('declarations', function ($sq) use ($annee, $mois) {
                $sq->when($annee, fn($q) => $q->whereYear('created_at', $annee))
                   ->when($mois,  fn($q) => $q->whereMonth('created_at', $mois));
            })->orWhereHas('documents', function ($sq) use ($annee, $mois) {
                $sq->when($annee, fn($q) => $q->whereYear('created_at', $annee))
                   ->when($mois,  fn($q) => $q->whereMonth('created_at', $mois));
            });
        })->count();

        return [
            'nouveaux_inscrits' => $totalUtilisateurs,
            'actifs'            => $utilisateursActifs,
        ];
    }

    // =========================================================================
    // COMPARAISON AVEC PÉRIODE PRÉCÉDENTE
    // =========================================================================

    private function getComparaisonPeriodePrecedente(int $annee, ?int $mois): array
    {
        // Déterminer la période précédente
        if ($mois) {
            $anneePrecedente = $mois === 1 ? $annee - 1 : $annee;
            $moisPrecedent   = $mois === 1 ? 12 : $mois - 1;
        } else {
            $anneePrecedente = $annee - 1;
            $moisPrecedent   = null;
        }

        $decCourante  = DeclarationDePerte::withTrashed()
            ->whereYear('created_at', $annee)
            ->when($mois, fn($q) => $q->whereMonth('created_at', $mois))
            ->count();

        $decPrecedente = DeclarationDePerte::withTrashed()
            ->whereYear('created_at', $anneePrecedente)
            ->when($moisPrecedent, fn($q) => $q->whereMonth('created_at', $moisPrecedent))
            ->count();

        $pubCourante  = Document::withTrashed()
            ->whereYear('created_at', $annee)
            ->when($mois, fn($q) => $q->whereMonth('created_at', $mois))
            ->count();

        $pubPrecedente = Document::withTrashed()
            ->whereYear('created_at', $anneePrecedente)
            ->when($moisPrecedent, fn($q) => $q->whereMonth('created_at', $moisPrecedent))
            ->count();

        return [
            'periode_precedente'         => $this->buildPeriodeLabel($anneePrecedente, $moisPrecedent),
            'declarations_variation'     => $this->calculerVariation($decPrecedente, $decCourante),
            'publications_variation'     => $this->calculerVariation($pubPrecedente, $pubCourante),
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Calcule la variation en % entre deux valeurs.
     * Retourne un tableau [valeur, sens, label]
     */
    private function calculerVariation(int $avant, int $apres): array
    {
        if ($avant === 0) {
            return [
                'valeur' => null,
                'sens'   => 'neutre',
                'label'  => 'Pas de données précédentes',
            ];
        }

        $variation = round((($apres - $avant) / $avant) * 100, 1);

        return [
            'valeur' => abs($variation),
            'sens'   => $variation > 0 ? 'hausse' : ($variation < 0 ? 'baisse' : 'stable'),
            'label'  => $variation > 0
                ? "+{$variation}% par rapport à la période précédente"
                : ($variation < 0 ? "{$variation}% par rapport à la période précédente" : "Stable"),
        ];
    }

    /**
     * Construit un label lisible pour la période
     */
    private function buildPeriodeLabel(int $annee, ?int $mois): string
    {
        if (!$mois) {
            return "Année {$annee}";
        }

        $moisLabels = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars',
            4 => 'Avril',   5 => 'Mai',     6 => 'Juin',
            7 => 'Juillet', 8 => 'Août',    9 => 'Septembre',
            10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];

        return $moisLabels[$mois] . ' ' . $annee;
    }
}
