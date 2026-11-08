<?php

namespace Tests\Feature\Assistant;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Questions générales : fiches d'aide (config/assistant_faq.php), puis réponse rédigée par l'IA
 * À PARTIR DES FICHES uniquement, avec garde-fous.
 */
class AssistantFaqTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.stt.provider' => 'none', 'ai.llm.provider' => 'none', 'assistant_faq.providers' => []]);
    }

    private function ask(string $text)
    {
        return $this->postJson('/api/v2/assistant/messages', ['text' => $text])->assertOk();
    }

    private function enableAi(array $answer): void
    {
        config([
            'assistant_faq.providers' => ['qwen'],
            'ai.endpoints.qwen'       => ['url' => 'https://ia.test/v1', 'key' => 'cle-test', 'model' => 'qwen-plus'],
        ]);
        Http::fake(['ia.test/*' => Http::response(['model' => 'qwen-plus', 'choices' => [['message' => ['content' => json_encode($answer)]]]])]);
    }

    public function test_a_question_close_to_a_faq_entry_gets_its_answer_word_for_word(): void
    {
        $r = $this->ask('Est-ce que c\'est payant ?');

        $r->assertJsonPath('reply.messages.0.key', 'faq:gratuit');
        $this->assertStringContainsString('gratuites', $r->json('reply.messages.0.text'));
        $this->assertNotEmpty($r->json('reply.quick_replies'));
    }

    public function test_faq_answer_comes_with_links_to_the_right_pages(): void
    {
        $r = $this->ask('Comment déclarer une perte ?');

        $r->assertJsonPath('reply.messages.0.key', 'faq:declarer_perte')
            ->assertJsonPath('reply.card.type', 'links')
            ->assertJsonPath('reply.card.data.links.0.url', '/declarer');
    }

    public function test_a_real_question_wins_over_an_action_keyword(): void
    {
        // « trouveur » ressemble à « j'ai trouvé » : la question sur l'argent reste prioritaire.
        $this->ask('Le trouveur me demande de l\'argent, que faire ?')->assertJsonPath('reply.messages.0.key', 'faq:trouveur_argent');
        // Une action reste une action.
        $this->ask('J\'ai trouvé un passeport')->assertJsonMissing(['key' => 'faq:publier_trouve']);
    }

    public function test_owner_asking_how_to_know_is_not_taken_for_a_finder(): void
    {
        // « trouvé » ne veut pas dire « j'ai trouvé » : c'est le propriétaire qui s'inquiète.
        $this->ask('Comment savoir que mon document est trouvé')->assertJsonPath('reply.messages.0.key', 'faq:rechercher');
        $this->ask('Si on trouve mon passeport, je serai averti ?')->assertJsonPath('reply.messages.0.key', 'faq:rechercher');
        $this->ask('Quelqu\'un a trouvé ma carte, comment je fais ?')->assertJsonPath('reply.messages.0.key', 'faq:recuperer_trouveur');
    }

    public function test_denying_the_flow_cancels_it_and_answers_the_question(): void
    {
        $id = $this->ask('J\'ai trouvé un document')->json('conversation.id');

        $r = $this->postJson('/api/v2/assistant/messages', [
            'conversation_id' => $id,
            'text'            => 'non je n\'ai pas trouvé de document, je veux savoir comment je saurai que mon document est retrouvé',
        ])->assertOk();

        $r->assertJsonPath('reply.messages.0.key', 'faq:rechercher')
            ->assertJsonPath('conversation.flow', null);
    }

    public function test_a_help_question_during_a_flow_is_answered_then_the_step_is_asked_again(): void
    {
        $id = $this->ask('J\'ai trouvé un document')->json('conversation.id');

        $r = $this->postJson('/api/v2/assistant/messages', ['conversation_id' => $id, 'text' => 'Est-ce que c\'est payant ?'])->assertOk();

        $r->assertJsonPath('reply.messages.0.key', 'faq:gratuit')
            ->assertJsonPath('conversation.flow', 'report_found');
        $this->assertCount(2, $r->json('reply.messages'));
    }

    public function test_ai_can_cite_a_section_of_the_knowledge_file(): void
    {
        $this->enableAi(['answer' => 'Non. DocTrack ne fabrique pas les pièces : adressez-vous à l\'administration compétente.', 'sources' => ['limites']]);

        $this->ask('Pouvez-vous me fabriquer une nouvelle pièce ?')
            ->assertJsonPath('reply.messages.0.key', 'faq:limites')
            ->assertJsonPath('reply.messages.1.key', 'faq_ai_notice');
    }

    public function test_links_of_a_cited_knowledge_section_are_shown(): void
    {
        $this->enableAi(['answer' => 'Déclarez votre perte : vous serez prévenu par SMS et par e-mail.', 'sources' => ['etre_prevenu']]);
        $this->ask('Est-ce que vous envoyez un message sur WhatsApp ?')
            ->assertJsonPath('reply.card.type', 'links')
            ->assertJsonPath('reply.card.data.links.0.url', '/liste');
    }

    public function test_knowledge_file_is_parsed_into_sections(): void
    {
        $sections = app(\App\Services\Assistant\KnowledgeBase::class)->sections();

        $this->assertArrayHasKey('presentation', $sections);
        $this->assertArrayHasKey('restitution_directe', $sections);
        $this->assertStringContainsString('48 heures', $sections['restitution_directe']['answer']);
        $this->assertStringNotContainsString('Liens', $sections['declarer']['answer']);
        $this->assertSame('/declarer', $sections['declarer']['links'][0]['url']);
    }

    public function test_an_unanswered_question_gives_the_support_contacts(): void
    {
        config(['assistant_faq.support' => ['whatsapp' => '+221 70 000 00 00', 'whatsapp_url' => 'https://wa.me/221700000000', 'email' => 'aide@doctrack.test']]);

        $r = $this->ask('Est-ce que la mairie de mon quartier travaille avec vous ?');

        $r->assertJsonPath('reply.messages.0.key', 'no_answer_contact')
            ->assertJsonPath('reply.card.type', 'links')
            ->assertJsonPath('reply.card.data.links.0.url', 'https://wa.me/221700000000')
            ->assertJsonPath('reply.card.data.links.1.url', 'mailto:aide@doctrack.test');
        $this->assertStringContainsString('+221 70 000 00 00', $r->json('reply.messages.0.text'));
    }

    public function test_only_the_closest_sections_are_sent_to_the_ai(): void
    {
        $kb = app(\App\Services\Assistant\KnowledgeBase::class);
        $ids = array_keys($kb->relevantSections('Comment obtenir un certificat de perte au commissariat ?', 6));

        $this->assertContains('recepisse_certificat', $ids);
        $this->assertContains('contact', $ids);
        $this->assertLessThanOrEqual(7, count($ids));
    }

    public function test_without_ai_an_unknown_question_falls_back_to_the_menu(): void
    {
        $this->ask('xyz abc')->assertJsonPath('reply.messages.0.key', 'not_understood');
        Http::assertNothingSent();
    }

    public function test_ai_answers_from_the_faq_only_and_never_receives_numbers(): void
    {
        $this->enableAi(['answer' => 'Non, tout est gratuit : DocTrack ne vous demandera jamais d\'argent pour vous rendre un document.', 'sources' => ['gratuit']]);

        $r = $this->ask('Faut-il sortir le portefeuille ? Mon code 1234567890');

        $r->assertJsonPath('reply.messages.0.key', 'faq:gratuit')
            ->assertJsonPath('reply.messages.1.key', 'faq_ai_notice');
        Http::assertSent(fn (Request $req) => ! str_contains($req->body(), '1234567890') && str_contains($req->body(), '##########'));
    }

    public function test_ai_answer_with_an_invented_number_is_rejected(): void
    {
        $this->enableAi(['answer' => 'Le délai est de 15 jours.', 'sources' => ['delai']]);

        $this->ask('Au bout de combien de semaines je saurai ?')->assertJsonPath('reply.messages.0.key', 'no_answer_contact');
    }

    public function test_ai_answer_without_a_known_source_is_rejected(): void
    {
        $this->enableAi(['answer' => 'Allez à la mairie.', 'sources' => ['inventee']]);

        $this->ask('Puis-je aller à la mairie ?')->assertJsonPath('reply.messages.0.key', 'no_answer_contact');
    }

    public function test_a_saturated_ai_asks_to_try_again_instead_of_saying_it_does_not_know(): void
    {
        config([
            'assistant_faq.providers' => ['qwen'],
            'ai.endpoints.qwen'       => ['url' => 'https://ia.test/v1', 'key' => 'cle-test', 'model' => 'qwen-plus'],
        ]);
        Http::fake(['ia.test/*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429)]);

        $this->ask('Est-ce que la mairie de mon quartier travaille avec vous ?')
            ->assertJsonPath('reply.messages.0.key', 'ai_busy')
            ->assertJsonPath('reply.card.type', 'links');
    }

    public function test_ai_calls_are_capped_per_conversation(): void
    {
        config(['assistant_faq.limits.per_conversation' => 1]);
        $this->enableAi(['answer' => null, 'sources' => []]);

        $id = $this->ask('Une question bizarre numéro un ?')->json('conversation.id');
        $this->postJson('/api/v2/assistant/messages', ['conversation_id' => $id, 'text' => 'Une autre question étrange ici ?'])->assertOk();

        Http::assertSentCount(1);
    }
}
