<?php

namespace App\Services\Matching;

use App\Models\DeclarationItem;
use App\Models\Document;

/**
 * Contrat d'un moteur de correspondance.
 *
 * Le moteur à règles (RuleBasedMatchingEngine) l'implémente aujourd'hui.
 * Un moteur IA (service Python, modèle entraîné sur les validations des agents…)
 * pourra l'implémenter demain, sans rien changer au reste de l'application.
 */
interface MatchingEngine
{
    public function name(): string;

    public function version(): string;

    public function score(DeclarationItem $item, Document $document): MatchResult;
}
