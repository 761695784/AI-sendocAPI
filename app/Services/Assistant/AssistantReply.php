<?php

namespace App\Services\Assistant;

/** Réponse de l'assistant à un message. */
class AssistantReply
{
    /** @var array<int, array{key: string, text: string, audio_url: ?string}> */
    public array $messages = [];

    /** @var array<int, array{label: string, value: string}> */
    public array $quickReplies = [];

    /** Carte d'action pour l'interface (ex. ouvrir un formulaire pré-rempli). */
    public ?array $card = null;

    public ?string $intent = null;

    /** Données d'audit (ex. déclaration créée). */
    public array $meta = [];

    public function add(array $message): static
    {
        $this->messages[] = $message;

        return $this;
    }

    public function buttons(array $buttons): static
    {
        $this->quickReplies = array_values($buttons);

        return $this;
    }

    public function text(): string
    {
        return implode("\n", array_column($this->messages, 'text'));
    }

    public function toArray(): array
    {
        return [
            'messages'      => $this->messages,
            'quick_replies' => $this->quickReplies,
            'card'          => $this->card,
        ];
    }
}
