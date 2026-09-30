<?php

namespace App\Services\Ai;

class NullIntentClassifier implements IntentClassifier
{
    public function available(): bool
    {
        return false;
    }

    public function classify(string $text, array $intents, array $documentTypes): ?array
    {
        return null;
    }
}
