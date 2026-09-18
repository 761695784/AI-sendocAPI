<?php

namespace App\Services;

class AnalyseReglesService
{
    // =========================================================================
    // SEUILS MÉTIER — ajuste ces valeurs selon la réalité du terrain
    // =========================================================================

    // Taux de restitution
    private const SEUIL_RESTITUTION_CRITIQUE  = 10;  // % → alerte rouge
    private const SEUIL_RESTITUTION_FAIBLE    = 25;  // % → alerte orange
    private const SEUIL_RESTITUTION_BON       = 50;  // % → constat positif

    // Variation par rapport à la période précédente
    private const SEUIL_HAUSSE_CRITIQUE       = 30;  // % → hausse forte = alerte
    private const SEUIL_HAUSSE_NOTABLE        = 15;  // % → hausse notable = vigilance
    private const SEUIL_BAISSE_NOTABLE        = 15;  // % → baisse notable = constat positif

    // Concentration géographique
    private const SEUIL_CONCENTRATION_REGION  = 60;  // % → si 1 région dépasse ce % du total
    private const SEUIL_MIN_PUBLICATIONS_ZONE = 2;   // publications min pour analyser une zone

    // Correspondance déclarations / publications
    private const SEUIL_RATIO_FAIBLE          = 20;  // % → peu de docs trouvés vs déclarés
    private const SEUIL_RATIO_BON             = 50;  // % → bon ratio de correspondance

    // =========================================================================
    // POINT D'ENTRÉE PRINCIPAL
    // =========================================================================

    /**
     * Génère l'analyse complète à partir des stats.
     * Retourne un tableau structuré prêt à être injecté dans la vue Blade.
     */
    public function analyser(array $stats): array
    {
        return [
            'constat_general'     => $this->genererConstatGeneral($stats),
            'alertes'             => $this->genererAlertes($stats),
            'points_positifs'     => $this->genererPointsPositifs($stats),
            'analyse_geographique'=> $this->genererAnalyseGeographique($stats),
            'analyse_temporelle'  => $this->genererAnalyseTemporelle($stats),
            'recommandations'     => $this->genererRecommandations($stats),
            'niveau_global'       => $this->evaluerNiveauGlobal($stats),
        ];
    }

    // =========================================================================
    // CONSTAT GÉNÉRAL
    // =========================================================================

    private function genererConstatGeneral(array $stats): string
    {
        $periode      = $stats['periode'];
        $totalDec     = $stats['declarations']['total'];
        $totalPub     = $stats['publications']['total'];
        $taux         = $stats['publications']['taux_restitution'];
        $nbLocalites  = $stats['par_region']['nombre_localites'];

        // Phrase 1 — volume global
        if ($totalDec === 0 && $totalPub === 0) {
            return "Aucune activité enregistrée sur la plateforme DocTrack pour la période {$periode}.";
        }

        $constat = "Pour la période {$periode}, la plateforme DocTrack a enregistré "
            . $this->formatNombre($totalDec) . " déclaration(s) de perte "
            . "et " . $this->formatNombre($totalPub) . " publication(s) de documents trouvés, "
            . "couvrant " . $this->formatNombre($nbLocalites) . " localité(s) à travers le territoire national.";

        // Phrase 2 — taux de restitution
        if ($taux >= self::SEUIL_RESTITUTION_BON) {
            $constat .= " Le taux de restitution s'établit à {$taux}%, témoignant d'une efficacité satisfaisante de la plateforme.";
        } elseif ($taux >= self::SEUIL_RESTITUTION_FAIBLE) {
            $constat .= " Le taux de restitution de {$taux}% indique une marge d'amélioration significative dans le processus de récupération des documents.";
        } elseif ($taux > 0) {
            $constat .= " Le taux de restitution de {$taux}% reste préoccupant et appelle des mesures correctives urgentes.";
        } else {
            $constat .= " Aucun document n'a été restitué à son propriétaire au cours de cette période.";
        }

        // Phrase 3 — tendance vs période précédente
        $varDec = $stats['comparaison']['declarations_variation'];
        if ($varDec['sens'] === 'hausse' && $varDec['valeur'] >= self::SEUIL_HAUSSE_NOTABLE) {
            $constat .= " On note une hausse de {$varDec['valeur']}% des déclarations par rapport à la période précédente ({$stats['comparaison']['periode_precedente']}).";
        } elseif ($varDec['sens'] === 'baisse' && $varDec['valeur'] >= self::SEUIL_BAISSE_NOTABLE) {
            $constat .= " Une baisse de {$varDec['valeur']}% des déclarations est observée par rapport à la période précédente ({$stats['comparaison']['periode_precedente']}).";
        }

        return $constat;
    }

    // =========================================================================
    // ALERTES (points négatifs à signaler)
    // =========================================================================

    private function genererAlertes(array $stats): array
    {
        $alertes = [];

        // ── Alerte 1 : taux de restitution critique ──────────────────────────
        $taux = $stats['publications']['taux_restitution'];
        if ($taux <= self::SEUIL_RESTITUTION_CRITIQUE && $stats['publications']['total'] > 0) {
            $alertes[] = [
                'niveau'  => 'critique',
                'icone'   => '🔴',
                'titre'   => 'Taux de restitution critique',
                'message' => "Le taux de restitution est de {$taux}%, soit en dessous du seuil critique de "
                    . self::SEUIL_RESTITUTION_CRITIQUE . "%. Une intervention immédiate est requise pour améliorer "
                    . "les processus de mise en relation entre déclarants et détenteurs de documents.",
            ];
        } elseif ($taux <= self::SEUIL_RESTITUTION_FAIBLE && $stats['publications']['total'] > 0) {
            $alertes[] = [
                'niveau'  => 'avertissement',
                'icone'   => '🟠',
                'titre'   => 'Taux de restitution insuffisant',
                'message' => "Avec un taux de restitution de {$taux}%, la plateforme n'atteint pas encore "
                    . "un niveau de performance satisfaisant. Des actions de sensibilisation sont recommandées.",
            ];
        }

        // ── Alerte 2 : forte hausse des déclarations ─────────────────────────
        $varDec = $stats['comparaison']['declarations_variation'];
        if ($varDec['sens'] === 'hausse' && $varDec['valeur'] >= self::SEUIL_HAUSSE_CRITIQUE) {
            $alertes[] = [
                'niveau'  => 'critique',
                'icone'   => '🔴',
                'titre'   => 'Augmentation anormale des déclarations',
                'message' => "Une hausse de {$varDec['valeur']}% des déclarations de perte a été enregistrée "
                    . "par rapport à {$stats['comparaison']['periode_precedente']}. "
                    . "Ce pic inhabituel mérite une investigation approfondie pour en identifier les causes.",
            ];
        } elseif ($varDec['sens'] === 'hausse' && $varDec['valeur'] >= self::SEUIL_HAUSSE_NOTABLE) {
            $alertes[] = [
                'niveau'  => 'avertissement',
                'icone'   => '🟠',
                'titre'   => 'Hausse notable des déclarations',
                'message' => "Les déclarations de perte ont augmenté de {$varDec['valeur']}% "
                    . "par rapport à {$stats['comparaison']['periode_precedente']}. "
                    . "Une surveillance accrue est recommandée.",
            ];
        }

        // ── Alerte 3 : zones critiques ───────────────────────────────────────
        $zonesCritiques = $stats['par_region']['zones_critiques'] ?? collect();
        if ($zonesCritiques->count() > 0) {
            $nomsZones = $zonesCritiques->pluck('region')->join(', ');
            $alertes[] = [
                'niveau'  => 'avertissement',
                'icone'   => '🟠',
                'titre'   => 'Zones à faible taux de restitution',
                'message' => "Les localités suivantes présentent un taux de restitution inférieur à "
                    . self::SEUIL_RESTITUTION_CRITIQUE . "% : {$nomsZones}. "
                    . "Ces zones nécessitent une attention particulière des autorités compétentes.",
            ];
        }

        // ── Alerte 4 : concentration géographique excessive ──────────────────
        $alerte = $this->detecterConcentrationGeographique($stats);
        if ($alerte) {
            $alertes[] = $alerte;
        }

        // ── Alerte 5 : ratio déclarations / publications déséquilibré ────────
        $totalDec = $stats['declarations']['total'];
        $totalPub = $stats['publications']['total'];
        if ($totalDec > 0 && $totalPub > 0) {
            $ratio = round(($totalPub / $totalDec) * 100, 1);
            if ($ratio < self::SEUIL_RATIO_FAIBLE) {
                $alertes[] = [
                    'niveau'  => 'information',
                    'icone'   => '🔵',
                    'titre'   => 'Faible ratio publications / déclarations',
                    'message' => "Seulement {$ratio}% du nombre de déclarations de perte correspond à des publications "
                        . "de documents trouvés. Cela suggère que de nombreux documents perdus ne sont pas "
                        . "retrouvés ou publiés sur la plateforme.",
                ];
            }
        }

        return $alertes;
    }

    // =========================================================================
    // POINTS POSITIFS
    // =========================================================================

    private function genererPointsPositifs(array $stats): array
    {
        $positifs = [];

        // ── Bon taux de restitution ───────────────────────────────────────────
        $taux = $stats['publications']['taux_restitution'];
        if ($taux >= self::SEUIL_RESTITUTION_BON) {
            $positifs[] = [
                'icone'   => '✅',
                'message' => "Le taux de restitution de {$taux}% dépasse le seuil satisfaisant de "
                    . self::SEUIL_RESTITUTION_BON . "%, témoignant de l'efficacité du système de mise en relation.",
            ];
        }

        // ── Baisse des déclarations (signe de moins de pertes) ────────────────
        $varDec = $stats['comparaison']['declarations_variation'];
        if ($varDec['sens'] === 'baisse' && $varDec['valeur'] >= self::SEUIL_BAISSE_NOTABLE) {
            $positifs[] = [
                'icone'   => '✅',
                'message' => "La baisse de {$varDec['valeur']}% des déclarations de perte par rapport à "
                    . "{$stats['comparaison']['periode_precedente']} peut indiquer une amélioration "
                    . "de la vigilance citoyenne ou l'effet des campagnes de sensibilisation.",
            ];
        }

        // ── Hausse des publications (plus de docs trouvés signalés) ──────────
        $varPub = $stats['comparaison']['publications_variation'];
        if ($varPub['sens'] === 'hausse' && $varPub['valeur'] >= self::SEUIL_HAUSSE_NOTABLE) {
            $positifs[] = [
                'icone'   => '✅',
                'message' => "La hausse de {$varPub['valeur']}% des publications de documents trouvés reflète "
                    . "une adoption croissante de la plateforme par les citoyens.",
            ];
        }

        // ── Couverture géographique étendue ───────────────────────────────────
        $nbLocalites = $stats['par_region']['nombre_localites'];
        if ($nbLocalites >= 5) {
            $positifs[] = [
                'icone'   => '✅',
                'message' => "La plateforme couvre {$nbLocalites} localités distinctes, témoignant "
                    . "d'une bonne diffusion territoriale du dispositif DocTrack.",
            ];
        }

        // ── Bon ratio publications / déclarations ─────────────────────────────
        $totalDec = $stats['declarations']['total'];
        $totalPub = $stats['publications']['total'];
        if ($totalDec > 0 && $totalPub > 0) {
            $ratio = round(($totalPub / $totalDec) * 100, 1);
            if ($ratio >= self::SEUIL_RATIO_BON) {
                $positifs[] = [
                    'icone'   => '✅',
                    'message' => "Le ratio publications/déclarations de {$ratio}% indique que la plateforme "
                        . "bénéficie d'une utilisation équilibrée aussi bien pour les déclarations de perte "
                        . "que pour le signalement de documents trouvés.",
                ];
            }
        }

        return $positifs;
    }

    // =========================================================================
    // ANALYSE GÉOGRAPHIQUE
    // =========================================================================

    private function genererAnalyseGeographique(array $stats): string
    {
        $regions = $stats['par_region']['liste'];
        $zoneLaPlusTouchee = $stats['par_region']['zone_la_plus_touchee'];

        if (!$zoneLaPlusTouchee || $regions->isEmpty()) {
            return "Aucune donnée géographique disponible pour cette période.";
        }

        $totalPub    = $stats['publications']['total'];
        $topRegion   = $zoneLaPlusTouchee['region'];
        $topCount    = $zoneLaPlusTouchee['total_publications'];
        $topPct      = $totalPub > 0 ? round(($topCount / $totalPub) * 100, 1) : 0;
        $topTaux     = $zoneLaPlusTouchee['taux_restitution'];

        $analyse = "Sur le plan géographique, la localité de {$topRegion} concentre le plus grand nombre "
            . "de publications avec {$topCount} document(s) signalé(s), soit {$topPct}% du total national.";

        // Commentaire sur le taux de cette zone
        if ($topTaux >= self::SEUIL_RESTITUTION_BON) {
            $analyse .= " Cette zone affiche également un bon taux de restitution de {$topTaux}%.";
        } elseif ($topTaux < self::SEUIL_RESTITUTION_FAIBLE) {
            $analyse .= " Cependant, le taux de restitution dans cette zone reste faible à {$topTaux}%,"
                . " ce qui mérite une attention particulière.";
        }

        // Concentration géographique
        if ($totalPub > 0 && $topPct >= self::SEUIL_CONCENTRATION_REGION) {
            $analyse .= " La forte concentration géographique des signalements ({$topPct}% dans une seule localité) "
                . "suggère que la plateforme est encore insuffisamment adoptée dans d'autres régions du pays.";
        }

        // Zones critiques
        $zonesCritiques = $stats['par_region']['zones_critiques'];
        if ($zonesCritiques->count() > 0) {
            $nomsZones = $zonesCritiques->pluck('region')->join(', ');
            $analyse .= " Les zones de {$nomsZones} présentent des taux de restitution particulièrement bas "
                . "et requièrent un suivi prioritaire.";
        }

        return $analyse;
    }

    // =========================================================================
    // ANALYSE TEMPORELLE
    // =========================================================================

    private function genererAnalyseTemporelle(array $stats): string
    {
        $evolution = $stats['evolution']['donnees'];
        $type      = $stats['evolution']['type'];

        if (empty($evolution)) {
            return "Aucune donnée temporelle disponible pour cette période.";
        }

        // Trouver le pic (mois / jour avec le plus de déclarations)
        $pic = collect($evolution)->sortByDesc('declarations')->first();

        if ($type === 'mensuelle') {
            $analyse = "L'analyse de l'évolution mensuelle révèle que le mois de {$pic['label']} "
                . "a enregistré le pic d'activité avec {$pic['declarations']} déclaration(s) de perte.";
        } else {
            $analyse = "L'analyse journalière indique que le {$pic['label']} du mois "
                . "a concentré le plus d'activité avec {$pic['declarations']} déclaration(s).";
        }

        // Calculer si la tendance est à la hausse ou baisse sur la période
        $tendance = $this->calculerTendance($evolution);
        if ($tendance === 'hausse') {
            $analyse .= " La tendance générale sur la période est à la hausse des déclarations.";
        } elseif ($tendance === 'baisse') {
            $analyse .= " La tendance générale sur la période est à la baisse des déclarations, "
                . "ce qui constitue un signal encourageant.";
        } else {
            $analyse .= " L'activité est restée relativement stable sur l'ensemble de la période.";
        }

        return $analyse;
    }

    // =========================================================================
    // RECOMMANDATIONS
    // =========================================================================

    private function genererRecommandations(array $stats): array
    {
        $recommandations = [];
        $priorite        = 1;

        $taux    = $stats['publications']['taux_restitution'];
        $varDec  = $stats['comparaison']['declarations_variation'];
        $zonesCritiques = $stats['par_region']['zones_critiques'];
        $nbLocalites    = $stats['par_region']['nombre_localites'];
        $totalDec       = $stats['declarations']['total'];
        $totalPub       = $stats['publications']['total'];

        // ── R1 : Taux de restitution faible ──────────────────────────────────
        if ($taux < self::SEUIL_RESTITUTION_BON) {
            $recommandations[] = [
                'priorite' => $priorite++,
                'titre'    => 'Renforcer le processus de mise en relation',
                'texte'    => "Avec un taux de restitution de {$taux}%, il est recommandé d'améliorer "
                    . "les mécanismes de notification et de suivi entre déclarants et détenteurs de documents. "
                    . "L'activation des alertes SMS et email automatiques pour toute correspondance détectée "
                    . "devrait être prioritaire.",
                'acteurs'  => ['Équipe technique DocTrack', 'Ministère de l\'Intérieur'],
            ];
        }

        // ── R2 : Zones critiques ──────────────────────────────────────────────
        if ($zonesCritiques->count() > 0) {
            $nomsZones = $zonesCritiques->pluck('region')->join(', ');
            $recommandations[] = [
                'priorite' => $priorite++,
                'titre'    => 'Campagnes de sensibilisation ciblées',
                'texte'    => "Il est recommandé de déployer des campagnes de sensibilisation spécifiques "
                    . "dans les zones à faible performance : {$nomsZones}. "
                    . "Ces campagnes peuvent inclure des partenariats avec les collectivités locales, "
                    . "les commissariats et les mairies pour promouvoir l'utilisation de la plateforme.",
                'acteurs'  => ['Collectivités locales', 'Forces de sécurité', 'Mairies'],
            ];
        }

        // ── R3 : Forte hausse des déclarations ────────────────────────────────
        if ($varDec['sens'] === 'hausse' && $varDec['valeur'] >= self::SEUIL_HAUSSE_NOTABLE) {
            $recommandations[] = [
                'priorite' => $priorite++,
                'titre'    => 'Investigation sur la hausse des déclarations',
                'texte'    => "La hausse de {$varDec['valeur']}% des déclarations de perte nécessite une analyse "
                    . "approfondie pour en identifier les causes : événements particuliers, zones géographiques "
                    . "concernées, types de documents les plus touchés. Un rapport d'investigation spécifique "
                    . "est recommandé.",
                'acteurs'  => ['Direction de DocTrack', 'Direction de la Police Nationale'],
            ];
        }

        // ── R4 : Faible couverture géographique ───────────────────────────────
        if ($nbLocalites < 5) {
            $recommandations[] = [
                'priorite' => $priorite++,
                'titre'    => 'Extension de la couverture territoriale',
                'texte'    => "La plateforme ne couvre que {$nbLocalites} localité(s) sur l'ensemble du territoire. "
                    . "Il est recommandé de mettre en place un plan d'extension incluant la formation "
                    . "de points focaux dans chaque région administrative du Sénégal.",
                'acteurs'  => ['Ministère de l\'Intérieur', 'Gouverneurs de région'],
            ];
        }

        // ── R5 : Déséquilibre déclarations / publications ─────────────────────
        if ($totalDec > 0 && $totalPub > 0) {
            $ratio = round(($totalPub / $totalDec) * 100, 1);
            if ($ratio < self::SEUIL_RATIO_FAIBLE) {
                $recommandations[] = [
                    'priorite' => $priorite++,
                    'titre'    => 'Encourager le signalement de documents trouvés',
                    'texte'    => "Le faible ratio de {$ratio}% entre publications et déclarations indique que "
                        . "de nombreux citoyens qui trouvent des documents ne les publient pas sur la plateforme. "
                        . "Il est recommandé de mettre en place des incitations civiques et de simplifier "
                        . "le processus de publication pour les non-inscrits.",
                    'acteurs'  => ['Équipe communication DocTrack', 'Médias nationaux'],
                ];
            }
        }

        // ── R6 : Recommandation systématique sur les données ─────────────────
        $recommandations[] = [
            'priorite' => $priorite++,
            'titre'    => 'Amélioration de la qualité des données',
            'texte'    => "Il est recommandé d'implémenter une validation renforcée des informations "
                . "lors de la soumission des déclarations et publications (normalisation des noms de localités, "
                . "vérification des numéros de documents) afin d'améliorer la qualité des correspondances automatiques.",
            'acteurs'  => ['Équipe technique DocTrack'],
        ];

        return $recommandations;
    }

    // =========================================================================
    // NIVEAU GLOBAL DE LA SITUATION
    // =========================================================================

    /**
     * Évalue le niveau global : 'critique', 'préoccupant', 'acceptable', 'satisfaisant'
     */
    private function evaluerNiveauGlobal(array $stats): array
    {
        $taux    = $stats['publications']['taux_restitution'];
        $varDec  = $stats['comparaison']['declarations_variation'];
        $nbAlertsCritiques = 0;

        if ($taux <= self::SEUIL_RESTITUTION_CRITIQUE) {
            $nbAlertsCritiques++;
        }
        if ($varDec['sens'] === 'hausse' && ($varDec['valeur'] ?? 0) >= self::SEUIL_HAUSSE_CRITIQUE) {
            $nbAlertsCritiques++;
        }

        if ($nbAlertsCritiques >= 2) {
            return [
                'niveau' => 'critique',
                'couleur'=> '#dc2626',
                'label'  => 'Situation critique',
                'icone'  => '🔴',
            ];
        } elseif ($taux < self::SEUIL_RESTITUTION_FAIBLE || $nbAlertsCritiques === 1) {
            return [
                'niveau' => 'preoccupant',
                'couleur'=> '#ea580c',
                'label'  => 'Situation préoccupante',
                'icone'  => '🟠',
            ];
        } elseif ($taux < self::SEUIL_RESTITUTION_BON) {
            return [
                'niveau' => 'acceptable',
                'couleur'=> '#ca8a04',
                'label'  => 'Situation acceptable',
                'icone'  => '🟡',
            ];
        } else {
            return [
                'niveau' => 'satisfaisant',
                'couleur'=> '#16a34a',
                'label'  => 'Situation satisfaisante',
                'icone'  => '🟢',
            ];
        }
    }

    // =========================================================================
    // HELPERS PRIVÉS
    // =========================================================================

    /**
     * Détecte si une seule région concentre trop de publications
     */
    private function detecterConcentrationGeographique(array $stats): ?array
    {
        $totalPub = $stats['publications']['total'];
        $zoneLaPlusTouchee = $stats['par_region']['zone_la_plus_touchee'];

        if (!$zoneLaPlusTouchee || $totalPub === 0) {
            return null;
        }

        $pct = round(($zoneLaPlusTouchee['total_publications'] / $totalPub) * 100, 1);

        if ($pct >= self::SEUIL_CONCENTRATION_REGION) {
            return [
                'niveau'  => 'information',
                'icone'   => '🔵',
                'titre'   => 'Forte concentration géographique',
                'message' => "{$pct}% des publications sont concentrées dans la seule localité de "
                    . "{$zoneLaPlusTouchee['region']}. La plateforme DocTrack gagnerait à être "
                    . "davantage promue dans les autres régions du pays.",
            ];
        }

        return null;
    }

    /**
     * Calcule la tendance générale sur une série temporelle
     * Compare la première moitié vs la deuxième moitié de la période
     */
    private function calculerTendance(array $evolution): string
    {
        $n = count($evolution);
        if ($n < 4) {
            return 'stable';
        }

        $moitie     = (int) floor($n / 2);
        $premiere   = array_slice($evolution, 0, $moitie);
        $deuxieme   = array_slice($evolution, $moitie);

        $moyPremiere = array_sum(array_column($premiere, 'declarations')) / count($premiere);
        $moyDeuxieme = array_sum(array_column($deuxieme, 'declarations')) / count($deuxieme);

        if ($moyPremiere === 0) {
            return 'stable';
        }

        $variation = (($moyDeuxieme - $moyPremiere) / $moyPremiere) * 100;

        if ($variation >= 10) {
            return 'hausse';
        } elseif ($variation <= -10) {
            return 'baisse';
        }

        return 'stable';
    }

    /**
     * Formate un nombre en français (espaces pour milliers)
     */
    private function formatNombre(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }
}
