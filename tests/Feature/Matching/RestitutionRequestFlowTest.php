<?php

namespace Tests\Feature\Matching;

use App\Jobs\NotifyOwnerOfConfirmedMatchJob;
use App\Jobs\SendSmsJob;
use App\Models\CollectionRequest;
use App\Models\DeclarationDePerte;
use App\Models\DeclarationItem;
use App\Models\DocumentMatch;
use App\Models\DocumentType;
use App\Models\Notification as InAppNotification;
use App\Models\User;
use App\Notifications\MatchConfirmedNotification;
use App\Notifications\RestitutionRequestNotification;
use App\Notifications\RestitutionUpdateNotification;
use App\Models\RestitutionRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Restitution d'un document retrouvé :
 *  - publié par un citoyen → mise en relation immédiate (conditions acceptées, numéros échangés) ;
 *  - conservé par une structure → la structure est prévenue, retrait au guichet.
 */
class RestitutionRequestFlowTest extends OrganizationTestCase
{
    private DocumentType $cni;
    private User $owner;
    private User $finder;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
        Bus::fake([SendSmsJob::class]); // les autres jobs (matching, avertissement) tournent normalement

        $this->cni    = DocumentType::factory()->create(['TypeName' => 'Carte nationale d\'identité']);
        $this->owner  = $this->citizen(['FirstName' => 'Awa', 'Phone' => '+221771112233']);
        $this->finder = $this->citizen(['FirstName' => 'Moussa', 'Phone' => '+221784445566']);
    }

    private function declareOnline(): void
    {
        $this->asUser($this->owner)->postJson('/api/declarations', [
            'Title' => 'Perte CNI', 'FirstNameInDoc' => 'Awa', 'LastNameInDoc' => 'Ndiaye',
            'DocIdentification' => '1234567890123', 'document_type_id' => $this->cni->id,
        ])->assertCreated();
    }

    private function publish(array $data = []): string
    {
        return $this->asUser($this->finder)->post('/api/documents', array_merge([
            'image'             => UploadedFile::fake()->image('cni.jpg', 600, 400),
            'OwnerFirstName'    => 'Awa',
            'OwnerLastName'     => 'Njaay',
            'DocIdentification' => '1234 5678 90123',
            'Location'          => 'Dakar',
            'document_type_id'  => $this->cni->id,
        ], $data), ['Accept' => 'application/json'])->assertCreated()->json('document.uuid');
    }

    /** Demande de restitution avec les conditions de la remise directe acceptées. */
    private function ask(User $user, string $docUuid, array $extra = [])
    {
        return $this->asUser($user)->postJson("/api/documents/{$docUuid}/restitution", $extra + ['accept_terms' => true]);
    }

    public function test_owner_is_notified_by_email_and_sms_when_the_match_is_confirmed(): void
    {
        $this->declareOnline();
        $this->publish();

        $match = DocumentMatch::sole();
        $this->assertSame(DocumentMatch::STATUS_CONFIRMED, $match->status);
        $this->assertNotNull($match->notified_at);

        Notification::assertSentTo($this->owner, MatchConfirmedNotification::class);
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221771112233'
            && str_contains($job->message, 'Demandez la restitution au trouveur'));
        $this->assertTrue(InAppNotification::where('message', 'like', 'Correspondance confirmée%')->exists());

        // Jamais deux fois pour la même correspondance.
        NotifyOwnerOfConfirmedMatchJob::dispatchSync($match->id);
        Notification::assertSentToTimes($this->owner, MatchConfirmedNotification::class, 1);
    }

    public function test_agent_confirmation_also_notifies_the_owner(): void
    {
        $this->declareOnline();
        $this->publish(['DocIdentification' => null]); // noms seuls → revue par un agent

        $match = DocumentMatch::sole();
        $this->assertSame(DocumentMatch::STATUS_PENDING_REVIEW, $match->status);
        Notification::assertNothingSentTo($this->owner);

        $this->asUser($this->admin())->postJson('/api/v2/matches/' . $match->uuid . '/confirm')->assertOk();

        Notification::assertSentTo($this->owner, MatchConfirmedNotification::class);
    }

    public function test_owner_requests_and_both_immediately_get_the_other_number(): void
    {
        $this->declareOnline();
        $docUuid = $this->publish();

        // Sans accepter les conditions de la remise directe : refusé.
        $this->asUser($this->owner)->postJson("/api/documents/{$docUuid}/restitution")
            ->assertUnprocessable()->assertJsonValidationErrors('accept_terms');

        $response = $this->ask($this->owner, $docUuid)
            ->assertCreated()
            ->assertJsonPath('channel', 'direct')
            ->assertJsonPath('request.status', 'accepted')
            ->assertJsonPath('finder.phone', '+221784445566');
        $uuid = $response->json('request.uuid');

        // Le trouveur : email avec le numéro du demandeur, et SMS.
        Notification::assertSentTo($this->finder, RestitutionRequestNotification::class,
            fn ($n) => $n->fromUser->is($this->owner) && $n->ownerPhone === '+221771112233');
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221784445566' && str_contains($job->message, '+221771112233'));
        // Le demandeur : SMS avec le numéro du trouveur.
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221771112233' && str_contains($job->message, '+221784445566'));

        $this->asUser($this->finder)->getJson('/api/restitution-requests')->assertOk()
            ->assertJsonPath('data.0.my_role', 'finder')
            ->assertJsonPath('data.0.other_party.phone', '+221771112233');
        $this->asUser($this->owner)->getJson("/api/restitution-requests/{$uuid}")->assertJsonPath('other_party.phone', '+221784445566');
        $this->asUser($this->citizen())->getJson("/api/restitution-requests/{$uuid}")->assertForbidden();

        // Pas de seconde demande en parallèle.
        $this->ask($this->owner, $docUuid)->assertStatus(400);

        // Remise effectuée, déclarée par le trouveur.
        $this->asUser($this->finder)->post('/api/documents/' . $docUuid, ['_method' => 'PUT', 'statut' => 'récupéré'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(RestitutionRequest::STATUS_COMPLETED, RestitutionRequest::sole()->status);
        $this->assertSame(DeclarationDePerte::STATUS_RESTITUTED, DeclarationDePerte::sole()->status);
    }

    public function test_the_named_owner_can_ask_without_a_declaration(): void
    {
        $docUuid = $this->publish(['OwnerFirstName' => 'Fatou', 'OwnerLastName' => 'Sarr']);

        // Même nom que sur le document : accepté sans déclaration préalable.
        $fatou = $this->citizen(['FirstName' => 'Fatou', 'LastName' => 'SARR', 'Phone' => '+221775556677']);
        $this->ask($fatou, $docUuid)->assertCreated()->assertJsonPath('finder.phone', '+221784445566');
    }

    public function test_finder_declines_and_the_owner_is_told_the_structure_takes_over(): void
    {
        $this->commissariatA->update(['latitude' => 14.7160, 'longitude' => -17.4580]);
        $this->declareOnline();
        $docUuid = $this->publish(['contact_consent' => true, 'latitude' => 14.7150, 'longitude' => -17.4590]);
        $uuid    = $this->ask($this->owner, $docUuid)->json('request.uuid');

        $this->asUser($this->finder)->postJson("/api/restitution-requests/{$uuid}/decline", ['reason' => 'Je préfère passer par la police'])
            ->assertOk()->assertJsonPath('request.status', 'declined')->assertJsonPath('request.other_party.phone', null);

        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221771112233'
            && str_contains($job->message, 'Commissariat A va récupérer le document'));
        $this->assertTrue(CollectionRequest::sole()->transitions()->where('reason', 'like', 'Remise directe impossible%')->exists());
    }

    public function test_owner_can_withdraw_his_request(): void
    {
        $this->declareOnline();
        $docUuid = $this->publish();
        $uuid    = $this->ask($this->owner, $docUuid)->json('request.uuid');

        $this->asUser($this->finder)->postJson("/api/restitution-requests/{$uuid}/cancel")->assertForbidden();
        $this->asUser($this->owner)->postJson("/api/restitution-requests/{$uuid}/cancel")->assertOk();
        $this->assertSame(RestitutionRequest::STATUS_CANCELLED, RestitutionRequest::sole()->status);
    }

    public function test_request_is_refused_when_nothing_shows_the_requester_owns_the_document(): void
    {
        $docUuid = $this->publish();
        $stranger = $this->citizen(['FirstName' => 'Ibou', 'LastName' => 'Faye']);

        $this->ask($stranger, $docUuid)->assertForbidden()->assertJsonPath('code', 'identity_check')->assertJsonPath('has_number', true);
        $this->ask($stranger, $docUuid, ['doc_last4' => '9999'])->assertForbidden()->assertJsonPath('code', 'identity_check');

        Notification::assertNothingSentTo($this->finder);
        Bus::assertNotDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221784445566');

        // Les 4 derniers caractères exacts du numéro de la pièce suffisent.
        $this->ask($stranger, $docUuid, ['doc_last4' => '0123'])->assertCreated();
    }

    public function test_handover_closes_the_declaration(): void
    {
        $this->declareOnline();
        $docUuid = $this->publish();

        $this->asUser($this->finder)->post('/api/documents/' . $docUuid, ['_method' => 'PUT', 'statut' => 'récupéré'], ['Accept' => 'application/json'])->assertOk();

        $declaration = DeclarationDePerte::sole();
        $this->assertSame(DeclarationDePerte::STATUS_RESTITUTED, $declaration->status);
        $this->assertSame(DeclarationItem::STATUS_RESTITUTED, $declaration->items()->first()->status);
    }

    public function test_direct_request_is_possible_while_a_collection_is_open_and_the_structure_is_told(): void
    {
        $this->commissariatA->update(['latitude' => 14.7160, 'longitude' => -17.4580]);
        $this->declareOnline();
        $docUuid = $this->publish(['contact_consent' => true, 'latitude' => 14.7150, 'longitude' => -17.4590]);

        $this->ask($this->owner, $docUuid)->assertCreated();

        $collection = CollectionRequest::sole();
        $this->assertTrue($collection->isOpen());
        $this->assertTrue($collection->owner_identified_at !== null);
        $this->assertTrue($collection->transitions()->where('reason', 'like', '%propriétaire confirmé a demandé une remise directe%')->exists());
    }

    public function test_owner_of_a_counter_declaration_gets_an_sms_pointing_to_the_structure(): void
    {
        $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/declarations', [
            'citizen' => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 999 88 77'],
            'items'   => [['document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye', 'doc_number' => '1234567890123']],
        ])->assertCreated();

        $this->asUser($this->agent($this->commissariatB))->postJson('/api/v2/found-documents', [
            'document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye', 'doc_number' => '1234567890123',
        ])->assertCreated();

        $this->assertSame(DocumentMatch::STATUS_CONFIRMED, DocumentMatch::sole()->status);
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => str_contains((string) $job->phone, '779998877')
            && str_contains($job->message, 'conservé par Commissariat B'));
    }

    public function test_owner_is_told_where_to_go_once_the_structure_has_collected_it(): void
    {
        $this->commissariatA->update(['latitude' => 14.7160, 'longitude' => -17.4580]);
        $this->declareOnline();
        $docUuid = $this->publish(['contact_consent' => true, 'latitude' => 14.7150, 'longitude' => -17.4590]);

        $collection = CollectionRequest::sole();
        $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/collections/' . $collection->uuid . '/collected')->assertOk();

        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221771112233'
            && str_contains($job->message, 'maintenant conservé par Commissariat A'));

        // Le trouveur ne l'a plus : la demande est transmise à la structure (retrait au guichet).
        $this->asUser($this->owner)->postJson("/api/documents/{$docUuid}/restitution")
            ->assertCreated()
            ->assertJsonPath('channel', 'counter')
            ->assertJsonPath('custody.name', 'Commissariat A')
            ->assertJsonPath('request.status', 'pending');

        $request = RestitutionRequest::sole();
        $this->assertSame(RestitutionRequest::CHANNEL_COUNTER, $request->channel);
        $this->assertSame($this->commissariatA->id, $request->organization_id);
        $this->assertNull($request->finder_user_id);
        $this->assertTrue(\App\Models\InboxMessage::where('organization_id', $this->commissariatA->id)->where('event', 'restitution.counter_request')->exists());
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221771112233' && str_contains($job->message, 'transmise à Commissariat A'));

        $this->asUser($this->owner)->getJson('/api/restitution-requests')->assertOk()
            ->assertJsonPath('data.0.channel', 'counter')
            ->assertJsonPath('data.0.structure.name', 'Commissariat A');
    }

    public function test_my_declarations_show_the_found_document_and_the_next_step(): void
    {
        $this->declareOnline();
        $uuid = $this->publish();

        $found = $this->asUser($this->owner)->getJson('/api/my-declarations')->assertOk()->json('data.0.found_documents');
        $this->assertCount(1, $found);
        $this->assertSame($uuid, $found[0]['uuid']);
        $this->assertSame('direct', $found[0]['next_step']);
        $this->assertNull($found[0]['restitution_request']);
        $this->assertArrayNotHasKey('matches', $this->asUser($this->owner)->getJson('/api/my-declarations')->json('data.0'));

        // Demande envoyée → visible dans la déclaration.
        $this->ask($this->owner, $uuid)->assertSuccessful();
        $found = $this->asUser($this->owner)->getJson('/api/my-declarations')->json('data.0.found_documents.0');
        $this->assertSame('accepted', $found['restitution_request']['status']);
        $this->assertTrue($found['restitution_request']['active']);

        // Le numéro du trouveur reste dans « Remises », pas dans la liste des déclarations.
        $this->assertStringNotContainsString('784445566', $this->asUser($this->owner)->getJson('/api/my-declarations')->getContent());
    }

    public function test_senegalese_name_variants_are_recognised(): void
    {
        // Deux noms de famille sur le document, un seul sur le compte ; prénom composé.
        $docUuid = $this->publish(['OwnerFirstName' => 'Mame Fatou', 'OwnerLastName' => 'Diop Ndiaye']);
        $fatou = $this->citizen(['FirstName' => 'Fatou', 'LastName' => 'Ndiaye', 'Phone' => '+221775556600']);

        $this->ask($fatou, $docUuid)->assertCreated()->assertJsonPath('request.status', 'accepted');
    }

    public function test_without_proof_the_finder_decides(): void
    {
        // Le trouveur n'a pas saisi le numéro ; nom du compte différent (nom d'épouse).
        $docUuid = $this->publish(['DocIdentification' => null, 'OwnerFirstName' => 'Aminata', 'OwnerLastName' => 'Sow']);
        $aminata = $this->citizen(['FirstName' => 'Aminata', 'LastName' => 'Kane', 'Phone' => '+221775556611']);

        $this->ask($aminata, $docUuid)->assertForbidden()
            ->assertJsonPath('code', 'identity_check')->assertJsonPath('has_number', false);

        $uuid = $this->ask($aminata, $docUuid, ['ask_finder' => true])->assertCreated()
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('finder', null)
            ->json('request.uuid');

        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221784445566'
            && str_contains($job->message, 'Aminata Kane') && ! str_contains($job->message, '775556611'));
        $this->asUser($this->finder)->getJson('/api/restitution-requests')
            ->assertJsonPath('data.0.other_party.last_name', 'Kane')
            ->assertJsonPath('data.0.other_party.phone', null);

        // Le trouveur compare avec le document et accepte : les numéros sont échangés.
        $this->asUser($this->finder)->postJson("/api/restitution-requests/{$uuid}/accept")->assertOk()
            ->assertJsonPath('request.other_party.phone', '+221775556611');
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221775556611' && str_contains($job->message, '+221784445566'));
    }
}
