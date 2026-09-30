<?php

namespace App\Services\Ai;

/** Aide à comprendre une phrase libre que les règles n'ont pas reconnue. */
interface IntentClassifier
{
    public function available(): bool;

    /**
     * @param  string[]  $intents  intentions autorisées
     * @return array{intent: ?string, document_type: ?string}|null  null si indisponible ou incertain
     */
    public function classify(string $text, array $intents, array $documentTypes): ?array;
}
