<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Models\User;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\IntentClassifier;
use App\Services\Ai\SpeechToText;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantReply;
use App\Services\Assistant\Phrasebook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Assistant DocTrack (texte + voix). Accessible sans compte pour les
 * informations ; les actions personnelles (déclarer, suivre) demandent
 * d'être connecté (jeton JWT habituel, facultatif ici).
 */
class AssistantController extends Controller
{
    public function __construct(private AssistantEngine $engine, private Phrasebook $phrases) {}

    /** Ce que l'assistant sait faire sur ce serveur (pour l'interface). */
    public function capabilities(SpeechToText $stt, IntentClassifier $llm): JsonResponse
    {
        $audio = [];
        foreach (config('assistant.languages', []) as $lang) {
            // Seuls les audios encore fidèles au texte comptent (un texte corrigé invalide son audio).
            $audio[$lang] = count(array_filter(
                array_keys($this->phrases->staticPhrases($lang)),
                fn ($key) => $this->phrases->audioUrl($key, $lang) !== null
            ));
        }

        return response()->json([
            'enabled'          => (bool) config('assistant.enabled'),
            'languages'        => config('assistant.languages'),
            'default_language' => config('assistant.default_language'),
            'voice_input'      => $stt->available(),
            'llm_fallback'     => $llm->available(),
            // Réponses parlées : langues pour lesquelles des phrases ont un audio prêt.
            'voice_output'     => array_keys(array_filter($audio)),
            'audio_phrases'    => $audio,
        ]);
    }

    public function message(Request $request): JsonResponse
    {
        abort_unless(config('assistant.enabled'), 503, 'Assistant désactivé.');

        $data = $request->validate([
            'conversation_id' => 'nullable|uuid',
            'text'            => 'nullable|string|max:1000',
            'lang'            => 'nullable|in:' . implode(',', config('assistant.languages')),
            'input_mode'      => 'nullable|in:text,button',
            'latitude'        => 'nullable|numeric|between:-90,90',
            'longitude'       => 'nullable|numeric|between:-180,180',
        ]);

        $user = $this->currentUser();
        $conversation = $this->conversation($data['conversation_id'] ?? null, $user, $data['lang'] ?? null);
        $text = (string) ($data['text'] ?? '');
        $data['ip'] = $request->ip(); // plafond des réponses rédigées par l'IA (jamais transmise au fournisseur)

        $reply = $this->run($conversation, $text, $user, $data, $data['input_mode'] ?? 'text');

        return response()->json($this->payload($conversation, $reply));
    }

    public function voice(Request $request, SpeechToText $stt): JsonResponse
    {
        abort_unless(config('assistant.enabled'), 503, 'Assistant désactivé.');

        $data = $request->validate([
            'audio'           => 'required|file|max:' . config('ai.stt.max_upload_kb', 5120) . '|mimes:' . implode(',', config('ai.stt.mimes')),
            'conversation_id' => 'nullable|uuid',
            'lang'            => 'nullable|in:' . implode(',', config('assistant.languages')),
            'latitude'        => 'nullable|numeric|between:-90,90',
            'longitude'       => 'nullable|numeric|between:-180,180',
        ]);

        $user = $this->currentUser();
        $conversation = $this->conversation($data['conversation_id'] ?? null, $user, $data['lang'] ?? null);
        $lang = $conversation->language;
        $file = $request->file('audio');

        try {
            // Le fichier audio n'est jamais conservé : seul le texte transcrit est gardé.
            $result = $stt->transcribe($file->getRealPath(), $lang, $file->getClientOriginalName());
        } catch (AiUnavailableException) {
            $reply = (new AssistantReply())->add($this->phrases->say('voice_unavailable', $lang));

            return response()->json($this->payload($conversation, $reply) + ['transcript' => null], 503);
        }

        if (trim($result['text']) === '') {
            $reply = (new AssistantReply())->add($this->phrases->say('voice_not_understood', $lang));

            return response()->json($this->payload($conversation, $reply) + ['transcript' => '']);
        }

        $reply = $this->run($conversation, $result['text'], $user, $data, 'voice', ['stt_model' => $result['model'] ?? null]);

        return response()->json($this->payload($conversation, $reply) + ['transcript' => $result['text']]);
    }

    public function show(AssistantConversation $conversation): JsonResponse
    {
        $this->authorizeConversation($conversation, $this->currentUser());

        return response()->json([
            'conversation' => $this->conversationData($conversation),
            'messages'     => $conversation->messages()->get()->map(fn ($m) => [
                'direction'  => $m->direction,
                'input_mode' => $m->input_mode,
                'text'       => $m->text,
                'language'   => $m->language,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /** Audio pré-généré d'une phrase (aucune donnée personnelle : lecture publique). */
    public function audio(string $lang, string $key)
    {
        abort_unless(in_array($lang, config('assistant.languages', []), true), 404);
        $path = $this->phrases->audioPath($key, $lang);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type'  => 'audio/mpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    // ── interne ──────────────────────────────────────────────────────

    private function run(AssistantConversation $conversation, string $text, ?User $user, array $data, string $mode, array $meta = []): AssistantReply
    {
        if ($text !== '') {
            $conversation->messages()->create([
                'direction'  => 'in',
                'input_mode' => $mode,
                'text'       => $text,
                'language'   => $conversation->language,
                'meta'       => $meta ?: null,
            ]);
        }

        $reply = $this->engine->handle($conversation, $text, $user, [
            'latitude'  => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'ip'        => $data['ip'] ?? request()->ip(),
        ]);

        $conversation->messages()->create([
            'direction'  => 'out',
            'input_mode' => 'text',
            'text'       => $reply->text(),
            'language'   => $conversation->language,
            'intent'     => $reply->intent,
            'phrase_key' => $reply->messages[0]['key'] ?? null,
            'meta'       => array_filter([
                'keys' => array_column($reply->messages, 'key'),
                'card' => $reply->card['type'] ?? null,
            ] + $reply->meta),
        ]);

        return $reply;
    }

    private function payload(AssistantConversation $conversation, AssistantReply $reply): array
    {
        return [
            'conversation' => $this->conversationData($conversation->fresh() ?? $conversation),
            'reply'        => $reply->toArray(),
        ];
    }

    private function conversationData(AssistantConversation $c): array
    {
        return [
            'id'       => $c->uuid,
            'language' => $c->language,
            'flow'     => $c->flow,
            'step'     => $c->step,
            'status'   => $c->status,
        ];
    }

    private function conversation(?string $uuid, ?User $user, ?string $lang): AssistantConversation
    {
        if ($uuid) {
            $conversation = AssistantConversation::where('uuid', $uuid)->first();
            abort_unless($conversation, 404, 'Conversation introuvable.');
            $this->authorizeConversation($conversation, $user);
            if (! $conversation->user_id && $user) {
                $conversation->user_id = $user->id;
            }
        } else {
            $conversation = new AssistantConversation([
                'user_id'  => $user?->id,
                'channel'  => 'web',
                'language' => $lang ?? config('assistant.default_language', 'fr'),
            ]);
        }

        if ($lang) {
            $conversation->language = $lang;
        }
        $conversation->save();

        return $conversation;
    }

    private function authorizeConversation(AssistantConversation $conversation, ?User $user): void
    {
        abort_if($conversation->user_id && $conversation->user_id !== $user?->id, 404, 'Conversation introuvable.');
    }

    /** Jeton facultatif : un jeton absent ou invalide = visiteur. */
    private function currentUser(): ?User
    {
        try {
            $user = Auth::guard('api')->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User && (! method_exists($user, 'isActive') || $user->isActive()) ? $user : null;
    }
}
