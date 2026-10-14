<?php

namespace Tests\Feature\Assistant;

use App\Services\Assistant\Phrasebook;
use App\Services\Assistant\VoiceLibrary;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Voix wolof de l'assistant : les phrases fixes sont lues une fois par le service
 * de synthèse (Adia_TTS), enregistrées, puis servies comme des fichiers.
 */
class AssistantVoiceTest extends OrganizationTestCase
{
    private string $mp3;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->mp3 = 'ID3' . str_repeat("\x00\xff\xfb\x90", 200);
        config([
            'ai.stt.provider' => 'none', 'ai.llm.provider' => 'none',
            'ai.tts.provider' => 'hf_space', 'ai.tts.hf_space.url' => 'https://doctrack-tts.hf.space', 'ai.tts.hf_space.token' => 'secret',
        ]);
    }

    private function fakeSpace(): void
    {
        Http::fake(['doctrack-tts.hf.space/*' => Http::response($this->mp3, 200, [
            'Content-Type' => 'audio/mpeg', 'X-Model' => 'CONCREE/Adia_TTS', 'X-Duration-S' => '4.20', 'X-Elapsed-S' => '38.5',
        ])]);
    }

    public function test_command_generates_missing_phrases_and_resumes(): void
    {
        $this->fakeSpace();
        $total = count(app(Phrasebook::class)->staticPhrases('wo'));

        $this->artisan('doctrack:assistant-voices', ['--key' => ['welcome', 'menu']])->assertSuccessful();

        Storage::disk('local')->assertExists('assistant/audio/wo/welcome.mp3');
        $this->assertSame($this->mp3, Storage::disk('local')->get('assistant/audio/wo/welcome.mp3'));
        $manifest = json_decode(Storage::disk('local')->get('assistant/audio/wo/manifest.json'), true);
        $this->assertSame('CONCREE/Adia_TTS', $manifest['welcome']['model']);
        $this->assertSame(4.2, $manifest['welcome']['duration']);

        // Le texte wolof de la phrase est envoyé tel quel, avec la clé partagée ; rien d'autre.
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://doctrack-tts.hf.space/synthesize'
            && $r->hasHeader('Authorization', 'Bearer secret')
            && $r['lang'] === 'wo' && $r['format'] === 'mp3'
            && $r['text'] === config('assistant.phrases.welcome.wo'));
        Http::assertSentCount(2);

        // Relance sans option : seulement ce qui manque.
        $this->artisan('doctrack:assistant-voices')->assertSuccessful();
        Http::assertSentCount($total);
        $this->assertSame(['total' => $total, 'ready' => $total, 'stale' => 0, 'missing' => 0, 'manual' => 0], app(VoiceLibrary::class)->summary('wo'));

        // Tout est à jour : plus aucun appel.
        $this->artisan('doctrack:assistant-voices')->assertSuccessful();
        Http::assertSentCount($total);
    }

    public function test_assistant_reply_carries_the_generated_voice(): void
    {
        $this->fakeSpace();
        $this->artisan('doctrack:assistant-voices', ['--key' => ['welcome']])->assertSuccessful();

        $r = $this->postJson('/api/v2/assistant/messages', ['text' => '', 'lang' => 'wo'])->assertOk();
        $url = $r->json('reply.messages.0.audio_url');
        $this->assertStringContainsString('/api/v2/assistant/audio/wo/welcome?v=', $url);
        $this->get('/api/v2/assistant/audio/wo/welcome')->assertOk()->assertHeader('Content-Type', 'audio/mpeg');

        $this->getJson('/api/v2/assistant/capabilities')->assertOk()
            ->assertJsonPath('audio_phrases.wo', 1)->assertJsonPath('voice_output', ['wo']);
    }

    public function test_corrected_text_invalidates_its_audio_until_regenerated(): void
    {
        $this->fakeSpace();
        $this->artisan('doctrack:assistant-voices', ['--key' => ['welcome']])->assertSuccessful();
        $old = config('assistant.phrases.welcome.wo');

        // Un locuteur natif corrige la phrase : la voix dirait autre chose que le texte affiché.
        config(['assistant.phrases.welcome.wo' => 'Salaam aleekum. Maa ngi fi ngir dimbali la.']);

        $r = $this->postJson('/api/v2/assistant/messages', ['text' => '', 'lang' => 'wo'])->assertOk();
        $this->assertNull($r->json('reply.messages.0.audio_url'));
        $this->getJson('/api/v2/assistant/capabilities')->assertJsonPath('audio_phrases.wo', 0)->assertJsonPath('voice_output', []);

        $row = collect(app(VoiceLibrary::class)->status('wo'))->firstWhere('key', 'welcome');
        $this->assertSame('stale', $row['state']);
        $this->assertSame($old, $row['spoken_text']);

        // La commande refait seulement cette phrase.
        Http::fake(['doctrack-tts.hf.space/*' => Http::response($this->mp3, 200, ['Content-Type' => 'audio/mpeg'])]);
        $this->artisan('doctrack:assistant-voices', ['--key' => ['welcome']])->assertSuccessful();
        $this->assertNotNull($this->postJson('/api/v2/assistant/messages', ['text' => '', 'lang' => 'wo'])->json('reply.messages.0.audio_url'));
    }

    public function test_phrases_with_personal_details_are_never_sent(): void
    {
        $this->fakeSpace();
        $this->artisan('doctrack:assistant-voices')->assertSuccessful();

        // « confirm_loss » contient le nom et la pièce du citoyen : pas d'audio fixe, donc jamais envoyée.
        Http::assertNotSent(fn (HttpRequest $r) => preg_match('/:[a-z_]+/', (string) $r['text']) === 1);
        Storage::disk('local')->assertMissing('assistant/audio/wo/confirm_loss.mp3');
        $this->asUser($this->admin())->postJson('/api/v2/assistant/voices/wo/confirm_loss')->assertNotFound();
    }

    public function test_super_admin_lists_generates_and_removes_voices(): void
    {
        $this->fakeSpace();
        $admin = $this->admin();

        $this->getJson('/api/v2/assistant/voices')->assertUnauthorized();
        $this->asUser($this->manager($this->commissariatA))->getJson('/api/v2/assistant/voices')->assertForbidden();

        $this->asUser($admin)->getJson('/api/v2/assistant/voices?lang=wo')->assertOk()
            ->assertJsonPath('can_generate', true)->assertJsonPath('summary.ready', 0)
            ->assertJsonPath('data.0.key', 'welcome')->assertJsonPath('data.0.state', 'missing');

        $this->asUser($admin)->postJson('/api/v2/assistant/voices/wo/welcome')->assertOk()
            ->assertJsonPath('state', 'ready')->assertJsonPath('model', 'CONCREE/Adia_TTS');
        $this->asUser($admin)->getJson('/api/v2/assistant/voices')->assertJsonPath('summary.ready', 1);

        $this->asUser($admin)->deleteJson('/api/v2/assistant/voices/wo/welcome')->assertOk()->assertJsonPath('state', 'missing');
        Storage::disk('local')->assertMissing('assistant/audio/wo/welcome.mp3');

        // Le modèle ne lit que le wolof.
        $this->asUser($admin)->getJson('/api/v2/assistant/voices?lang=fr')->assertOk()->assertJsonPath('can_generate', false);
        $this->asUser($admin)->postJson('/api/v2/assistant/voices/fr/welcome')->assertStatus(503);
    }

    public function test_sleeping_or_broken_service_never_stores_a_bad_file(): void
    {
        $admin = $this->admin();

        // Space en veille : page HTML au lieu d'un audio.
        Http::fake(['doctrack-tts.hf.space/*' => Http::response('<html>Your space is sleeping</html>', 200, ['Content-Type' => 'text/html'])]);
        $this->asUser($admin)->postJson('/api/v2/assistant/voices/wo/welcome')->assertStatus(503);
        Storage::disk('local')->assertMissing('assistant/audio/wo/welcome.mp3');

        // Modèle encore en chargement.
        Http::fake(['doctrack-tts.hf.space/*' => Http::response(['detail' => 'Modèle en cours de chargement'], 503)]);
        $this->artisan('doctrack:assistant-voices', ['--key' => ['welcome']])->assertFailed();
        Storage::disk('local')->assertMissing('assistant/audio/wo/welcome.mp3');

        // Sans configuration : la commande l'explique, rien n'est appelé.
        config(['ai.tts.provider' => 'none']);
        $this->artisan('doctrack:assistant-voices')->assertFailed();
        $this->asUser($admin)->getJson('/api/v2/assistant/voices')->assertJsonPath('can_generate', false);
    }

    public function test_hand_dropped_files_are_adopted(): void
    {
        // Ancienne méthode : ZIP produit sur Colab et décompressé à la main.
        Storage::disk('local')->put('assistant/audio/wo/menu.mp3', $this->mp3);
        $this->assertSame('manual', collect(app(VoiceLibrary::class)->status('wo'))->firstWhere('key', 'menu')['state']);
        $this->assertNotNull(app(Phrasebook::class)->audioUrl('menu', 'wo'));

        $this->artisan('doctrack:assistant-voices', ['--adopt' => true])->assertSuccessful();

        $this->assertSame('ready', collect(app(VoiceLibrary::class)->status('wo'))->firstWhere('key', 'menu')['state']);
    }
}
