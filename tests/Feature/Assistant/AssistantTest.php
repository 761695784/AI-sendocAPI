<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantConversation;
use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\DocumentType;
use App\Models\InboxMessage;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Assistant guidé : il comprend le français et des mots-clés wolof,
 * récapitule avant d'agir et réutilise les services DocTrack.
 */
class AssistantTest extends OrganizationTestCase
{
    private DocumentType $cni;

    private DocumentType $passeport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.stt.provider' => 'none', 'ai.llm.provider' => 'none']);
        $this->cni       = DocumentType::factory()->create(['TypeName' => "Carte nationale d'identité"]);
        $this->passeport = DocumentType::factory()->create(['TypeName' => 'Passeport']);
    }

    /** Envoie une suite de messages dans la même conversation ; renvoie la dernière réponse. */
    private function chat(array $texts, ?User $user = null, array $extra = [])
    {
        $conversation = null;
        $response = null;
        foreach ($texts as $text) {
            $request = $user ? $this->asUser($user) : $this;
            $response = $request->postJson('/api/v2/assistant/messages', array_filter([
                'conversation_id' => $conversation,
                'text'            => $text,
            ]) + $extra)->assertOk();
            $conversation = $response->json('conversation.id');
        }

        return $response;
    }

    private function keys($response): array
    {
        return array_column($response->json('reply.messages'), 'key');
    }

    public function test_empty_message_opens_the_menu(): void
    {
        $r = $this->chat(['']);

        $this->assertSame(['welcome'], $this->keys($r));
        $this->assertCount(5, $r->json('reply.quick_replies'));
        $this->assertNotNull($r->json('conversation.id'));
    }

    public function test_citizen_declares_a_loss_step_by_step(): void
    {
        $user = $this->citizen();

        $r = $this->chat([
            "Bonjour, j'ai perdu ma carte d'identité",   // type déjà compris
            'Je m\'appelle Awa Ndiaye',
            '1 234 5678 90123',
            'Marché HLM, Dakar',
        ], $user);

        $this->assertSame(['confirm_loss'], $this->keys($r));
        $summary = $r->json('reply.messages.0.text');
        $this->assertStringContainsString('Awa Ndiaye', $summary);
        $this->assertStringContainsString('0123', $summary);          // 4 derniers chiffres seulement
        $this->assertStringNotContainsString('1234567890123', $summary);
        $this->assertSame(0, DeclarationDePerte::count());            // rien avant le « oui »

        $conversation = $r->json('conversation.id');
        $done = $this->asUser($user)->postJson('/api/v2/assistant/messages', ['conversation_id' => $conversation, 'text' => 'oui'])->assertOk();

        $declaration = DeclarationDePerte::sole();
        $this->assertSame('Awa', $declaration->FirstNameInDoc);
        $this->assertSame('Ndiaye', $declaration->LastNameInDoc);
        $this->assertSame($this->cni->id, $declaration->document_type_id);
        $this->assertSame('Marché HLM, Dakar', $declaration->lost_place);
        $this->assertSame(Territory::where('name', 'Dakar')->where('level', 'department')->value('id'), $declaration->territory_id);
        $this->assertSame($user->id, $declaration->user_id);
        $this->assertSame(1, $declaration->items()->count());          // visible par le moteur de correspondance

        $this->assertSame(['loss_created'], $this->keys($done));
        $this->assertStringContainsString($declaration->reference, $done->json('reply.messages.0.text'));
        $this->assertSame('declaration_created', $done->json('reply.card.type'));

        $log = Activity::where('description', 'Déclaration de perte créée')->latest('id')->first();
        $this->assertSame('assistant', $log->properties['via']);
    }

    public function test_wolof_keywords_switch_the_conversation_to_wolof(): void
    {
        $user = $this->citizen();

        $r = $this->chat(['Salaam aleekum, sama kart dafa réer'], $user);

        $this->assertSame('wo', $r->json('conversation.language'));
        $this->assertSame('declare_loss', $r->json('conversation.flow'));
        $this->assertSame('full_name', $r->json('conversation.step'));   // « kart » = CNI
        $this->assertSame(['ask_owner_name'], $this->keys($r));
        $this->assertStringContainsString('tur', $r->json('reply.messages.0.text'));
    }

    public function test_user_answers_waaw_and_xamuma_in_wolof(): void
    {
        $user = $this->citizen();

        $r = $this->chat(['paspoor bi dafa réer', 'Moussa Diop', 'xamuma', 'xamuma', 'waaw'], $user);

        $this->assertSame(['loss_created'], $this->keys($r));
        $declaration = DeclarationDePerte::sole();
        $this->assertSame($this->passeport->id, $declaration->document_type_id);
        $this->assertNull($declaration->DocIdentification);
        $this->assertNull($declaration->lost_place);
    }

    public function test_answering_no_lets_the_citizen_fix_the_name(): void
    {
        $user = $this->citizen();

        $r = $this->chat(['perte de passeport', 'Ndiaye Awa', 'je ne sais pas', 'passer', 'non'], $user);
        $this->assertSame(['what_to_fix'], $this->keys($r));

        $conversation = $r->json('conversation.id');
        $send = fn ($t) => $this->asUser($user)->postJson('/api/v2/assistant/messages', ['conversation_id' => $conversation, 'text' => $t])->assertOk();

        $this->assertSame(['ask_first_name'], $this->keys($send('Le nom')));
        $this->assertSame(['ask_last_name'], $this->keys($send('Awa')));
        $summary = $send('Ndiaye');
        $this->assertSame(['confirm_loss'], $this->keys($summary));
        $this->assertStringContainsString('Awa Ndiaye', $summary->json('reply.messages.0.text'));
    }

    public function test_visitor_must_log_in_to_declare(): void
    {
        $r = $this->chat(["J'ai perdu mon permis"]);

        $this->assertSame(['login_required'], $this->keys($r));
        $this->assertSame('login_required', $r->json('reply.card.type'));
        $this->assertNull($r->json('conversation.flow'));
    }

    public function test_cancel_forgets_everything(): void
    {
        $user = $this->citizen();

        $r = $this->chat(["j'ai perdu ma cni", 'Awa Ndiaye', 'annuler'], $user);

        $this->assertSame(['cancelled'], $this->keys($r));
        $this->assertNull($r->json('conversation.flow'));
        $this->assertSame(0, DeclarationDePerte::count());
    }

    public function test_finder_gets_a_prefilled_publication_form(): void
    {
        $r = $this->chat(["J'ai trouvé un passeport", 'Fatou Sow', 'Gare routière de Ziguinchor', 'oui', 'oui']);

        $this->assertSame(['found_next_step'], $this->keys($r));
        $card = $r->json('reply.card');
        $this->assertSame('open_form', $card['type']);
        $this->assertSame('found_document', $card['data']['form']);
        $this->assertSame($this->passeport->id, $card['data']['prefill']['document_type_id']);
        $this->assertSame('Fatou', $card['data']['prefill']['OwnerFirstName']);
        $this->assertSame('Sow', $card['data']['prefill']['OwnerLastName']);
        $this->assertTrue($card['data']['prefill']['contact_consent']);
        $this->assertSame(Territory::where('name', 'Ziguinchor')->where('level', 'department')->value('code'), $card['data']['prefill']['territory_code']);
        $this->assertTrue($card['data']['login_required']);
        $this->assertSame(0, Document::count());   // la publication se fait avec la photo, dans le formulaire
    }

    public function test_track_status_lists_the_citizen_declarations(): void
    {
        $user = $this->citizen();
        $this->chat(["j'ai perdu ma cni", 'Awa Ndiaye', 'xamuma', 'xamuma', 'oui'], $user);

        $r = $this->chat(['Où en est mon dossier ?'], $user);

        $this->assertSame(['status_intro', 'status_line'], $this->keys($r));
        $this->assertStringContainsString(DeclarationDePerte::sole()->reference, $r->json('reply.messages.1.text'));
        $this->assertStringContainsString('recherche en cours', $r->json('reply.messages.1.text'));
    }

    public function test_where_to_go_names_the_structure_holding_the_document(): void
    {
        $user = $this->citizen();
        $this->chat(["j'ai perdu ma cni", 'Awa Ndiaye', 'xamuma', 'xamuma', 'oui'], $user);
        $declaration = DeclarationDePerte::sole();
        $this->commissariatA->update(['address' => 'Dieuppeul, Dakar', 'phone' => '33 800 00 00']);

        $document = Document::factory()->create([
            'document_type_id'        => $this->cni->id,
            'OwnerFirstName'          => 'Awa',
            'OwnerLastName'           => 'Ndiaye',
            'custody_organization_id' => $this->commissariatA->id,
            'lifecycle_status'        => Document::LIFECYCLE_MATCHED,
        ]);
        DocumentMatch::where('declaration_de_perte_id', $declaration->id)->delete();
        DocumentMatch::create([
            'declaration_de_perte_id' => $declaration->id,
            'declaration_item_id'     => $declaration->items()->value('id'),
            'document_id'             => $document->id,
            'engine'                  => 'rules',
            'engine_version'          => 'test',
            'score'                   => 95,
            'score_details'           => [],
            'status'                  => DocumentMatch::STATUS_CONFIRMED,
        ]);

        $r = $this->chat(['Fan laa koy jëlé ?'], $user);

        $this->assertSame(['where_custody'], $this->keys($r));
        $text = $r->json('reply.messages.0.text');
        $this->assertStringContainsString('Commissariat A', $text);
        $this->assertStringContainsString('33 800 00 00', $text);
        $this->assertStringContainsString($declaration->reference, $text);
        $this->assertSame('wo', $r->json('conversation.language'));
        $this->assertSame($this->commissariatA->uuid, $r->json('reply.card.data.uuid'));
    }

    public function test_visitor_finds_the_nearest_structure_by_town(): void
    {
        $this->brigadeZ->update(['territory_id' => Territory::where('name', 'Ziguinchor')->where('level', 'department')->value('id'), 'accepts_collections' => true, 'is_active' => true]);

        $first = $this->chat(['Où récupérer ma pièce ?']);
        $this->assertSame(['ask_location'], $this->keys($first));

        $r = $this->postJson('/api/v2/assistant/messages', ['conversation_id' => $first->json('conversation.id'), 'text' => 'Je suis à Ziguinchor'])->assertOk();

        $this->assertSame(['nearest_structure'], $this->keys($r));
        $this->assertStringContainsString('Brigade Z', $r->json('reply.messages.0.text'));
    }

    public function test_a_city_with_several_structures_asks_for_the_quarter(): void
    {
        $dakar = Territory::where('name', 'Dakar')->where('level', 'department')->value('id');
        $this->commissariatA->update(['name' => 'Commissariat de Dieuppeul', 'territory_id' => $dakar, 'accepts_collections' => true, 'is_active' => true]);
        $this->commissariatB->update(['name' => 'Commissariat de la Médina', 'territory_id' => $dakar, 'accepts_collections' => true, 'is_active' => true]);

        $id   = $this->chat(['Où récupérer ma pièce ?'])->json('conversation.id');
        $send = fn ($t, array $extra = []) => $this->postJson('/api/v2/assistant/messages', ['conversation_id' => $id, 'text' => $t] + $extra)->assertOk();

        // « Dakar » seul : on ne choisit pas au hasard, on liste et on demande le quartier.
        $r = $send('dakar');
        $this->assertSame(['several_structures'], $this->keys($r));
        $this->assertStringContainsString('Commissariat de Dieuppeul', $r->json('reply.messages.0.text'));
        $this->assertStringContainsString('Commissariat de la Médina', $r->json('reply.messages.0.text'));
        $this->assertContains('__position__', array_column($r->json('reply.quick_replies'), 'value'));

        // Le quartier désigne la structure.
        $r = $send('Médina');
        $this->assertSame(['nearest_structure'], $this->keys($r));
        $this->assertStringContainsString('Commissariat de la Médina', $r->json('reply.messages.0.text'));

        // « Pourquoi ? » : l'assistant explique son choix et cite les autres structures.
        $r = $send('pourquoi médina ?');
        $this->assertSame(['why_quarter', 'other_structures'], $this->keys($r));
        $this->assertStringContainsString('Commissariat de Dieuppeul', $r->json('reply.messages.1.text'));

        // Bouton « Partager ma position » sans coordonnées : on redemande le lieu.
        $this->assertSame(['position_unavailable'], $this->keys($send('__position__')));
    }

    public function test_information_questions(): void
    {
        $this->assertSame(['what_to_bring_info'], $this->keys($this->chat(['Que faut-il apporter ?'])));
        $this->assertSame(['proxy_info'], $this->keys($this->chat(['Mon frère peut-il la récupérer à ma place ?'])));
        $this->assertSame(['what_to_bring_info'], $this->keys($this->chat(['lan laa war indi'])));
    }

    public function test_two_misunderstandings_offer_a_human_agent(): void
    {
        $r1 = $this->chat(['xyz abc']);
        $this->assertSame(['not_understood'], $this->keys($r1));

        $r2 = $this->postJson('/api/v2/assistant/messages', ['conversation_id' => $r1->json('conversation.id'), 'text' => 'blablabla'])->assertOk();
        $this->assertSame(['offer_human'], $this->keys($r2));
    }

    public function test_handoff_notifies_the_super_admins(): void
    {
        $admin = $this->admin();
        $user  = $this->citizen(['FirstName' => 'Awa', 'LastName' => 'Ndiaye']);

        $r = $this->chat(['Je veux parler à un agent'], $user);

        $this->assertSame(['handoff_done', 'support_contact'], $this->keys($r));
        $this->assertSame(AssistantConversation::STATUS_HANDED_OFF, $r->json('conversation.status'));
        $message = InboxMessage::latest('id')->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('Awa Ndiaye', $message->body);
        $this->assertSame($admin->id, $message->user_id);
    }

    public function test_conversation_of_another_citizen_is_hidden(): void
    {
        $owner = $this->citizen();
        $r = $this->chat(['bonjour'], $owner);
        $id = $r->json('conversation.id');

        $this->asUser($this->citizen())->postJson('/api/v2/assistant/messages', ['conversation_id' => $id, 'text' => 'oui'])->assertNotFound();
        $this->getJson("/api/v2/assistant/conversations/{$id}")->assertNotFound();
        $this->asUser($owner)->getJson("/api/v2/assistant/conversations/{$id}")->assertOk()->assertJsonCount(2, 'messages');
    }

    public function test_language_can_be_switched_explicitly(): void
    {
        $r = $this->chat(['wolof']);

        $this->assertSame('wo', $r->json('conversation.language'));
        $this->assertSame(['language_switched', 'menu'], $this->keys($r));
    }

    public function test_prerecorded_audio_is_returned_when_available(): void
    {
        Storage::disk('local')->put('assistant/audio/wo/welcome.mp3', 'ID3fake');

        $r = $this->chat([''], null, ['lang' => 'wo']);

        $url = $r->json('reply.messages.0.audio_url');
        $this->assertStringEndsWith('/api/v2/assistant/audio/wo/welcome', $url);
        $this->get('/api/v2/assistant/audio/wo/welcome')->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->get('/api/v2/assistant/audio/wo/menu')->assertNotFound();
    }

    public function test_voice_message_is_transcribed_by_the_space(): void
    {
        config(['ai.stt.provider' => 'hf_space', 'ai.stt.hf_space.url' => 'https://doctrack-voice.hf.space']);
        Http::fake(['doctrack-voice.hf.space/transcribe' => Http::response(['text' => 'sama paspoor dafa reer', 'model' => 'omniASR_CTC_300M_v2'])]);
        $user = $this->citizen();

        $r = $this->asUser($user)->post('/api/v2/assistant/voice', [
            'audio' => UploadedFile::fake()->create('note.ogg', 40, 'audio/ogg'),
            'lang'  => 'wo',
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('sama paspoor dafa reer', $r->json('transcript'));
        $this->assertSame('declare_loss', $r->json('conversation.flow'));
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/transcribe') && str_contains($req->body(), 'wol_Latn'));
        $this->assertSame('voice', AssistantConversation::sole()->messages()->where('direction', 'in')->value('input_mode'));
    }

    public function test_voice_without_provider_asks_to_type(): void
    {
        $this->post('/api/v2/assistant/voice', [
            'audio' => UploadedFile::fake()->create('note.webm', 20, 'audio/webm'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('reply.messages.0.key', 'voice_unavailable');
    }

    public function test_llm_fallback_proposes_an_intent_with_digits_masked(): void
    {
        config(['ai.llm.provider' => 'gemini', 'ai.llm.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"intent": "what_to_bring", "document_type": null}']]]]],
        ])]);

        $r = $this->chat(['le gars du guichet veut quoi comme papiers 77 123 45 67']);

        $this->assertSame(['what_to_bring_info'], $this->keys($r));
        Http::assertSent(fn ($req) => ! str_contains($req->body(), '77 123') && str_contains($req->body(), '## ### ## ##'));
    }

    public function test_capabilities_and_phrase_export(): void
    {
        $this->getJson('/api/v2/assistant/capabilities')
            ->assertOk()
            ->assertJsonPath('voice_input', false)
            ->assertJsonPath('languages', ['fr', 'wo']);

        $this->artisan('doctrack:assistant-phrases', ['--lang' => 'wo'])->assertSuccessful();
        $rows = json_decode(Storage::disk('local')->get('assistant/phrases_wo.json'), true);
        $keys = array_column($rows, 'key');
        $this->assertContains('welcome', $keys);
        $this->assertNotContains('confirm_loss', $keys);  // phrase à paramètres : pas d'audio figé
    }

    public function test_old_conversations_are_pruned(): void
    {
        AssistantConversation::create(['last_activity_at' => now()->subDays(40)]);
        AssistantConversation::create(['last_activity_at' => now()->subDay()]);

        $this->artisan('doctrack:assistant-prune')->assertSuccessful();

        $this->assertSame(1, AssistantConversation::count());
    }
}
