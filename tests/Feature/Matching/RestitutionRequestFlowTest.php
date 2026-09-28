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
 * Correspondance confirmée → le propriétaire est prévenu et peut demander
 * la restitution directement au trouveur (SMS + email au trouveur).
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

    public function test_confirmed_owner_requests_and_the_finder_is_asked_without_seeing_the_number(): void
    {
        $this->declareOnline();
        $docUuid = $this->publish();

        $response = $this->asUser($this->owner)->postJson("/api/documents/{$docUuid}/restitution")
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending');
        $uuid = $response->json('request.uuid');

        Notification::assertSentTo($this->finder, RestitutionRequestNotification::class, fn ($n) => $n->fromUser->is($this->owner));
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221784445566'
            && str_contains($job->message, '/restitutions/' . $uuid)
            && ! str_contains($job->message, '771112233')); // double accord : pas de numéro avant l'accord

        // Le trouveur voit la demande, sans le numéro du propriétaire.
        $this->asUser($this->finder)->getJson('/api/restitution-requests')->assertOk()
            ->assertJsonPath('data.0.my_role', 'finder')
            ->assertJsonPath('data.0.other_party.phone', null);

        // Pas de seconde demande en parallèle.
        $this->asUser($this->owner)->postJson("/api/documents/{$docUuid}/restitution")->assertStatus(400);
    }

    public function test_finder_accepts_and_both_receive_the_other_number(): void
    {
        $this->declareOnline();
        $docUuid = $this->publish();
        $uuid    = $this->asUser($this->owner)->postJson("/api/documents/{$docUuid}/restitution")->json('request.uuid');

        $this->asUser($this->owner)->postJson("/api/restitution-requests/{$uuid}/accept")->assertForbidden(); // seul le trouveur répond

        $this->asUser($this->finder)->postJson("/api/restitution-requests/{$uuid}/accept")->assertOk()
            ->assertJsonPath('request.status', 'accepted')
            ->assertJsonPath('request.other_party.phone', '+221771112233');

        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221771112233' && str_contains($job->message, '+221784445566'));
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221784445566' && str_contains($job->message, '+221771112233'));
        Notification::assertSentTo($this->owner, RestitutionUpdateNotification::class, fn ($n) => $n->event === RestitutionUpdateNotification::CONTACT_FOR_OWNER);
        Notification::assertSentTo($this->finder, RestitutionUpdateNotification::class, fn ($n) => $n->event === RestitutionUpdateNotification::CONTACT_FOR_FINDER);

        $this->asUser($this->owner)->getJson("/api/restitution-requests/{$uuid}")->assertJsonPath('other_party.phone', '+221784445566');
        $this->asUser($this->citizen())->getJson("/api/restitution-requests/{$uuid}")->assertForbidden();
        $this->asUser($this->finder)->postJson("/api/restitution-requests/{$uuid}/accept")->assertStatus(409);

        // Remise effectuée, déclarée par le trouveur.
        $this->asUser($this->finder)->post('/api/documents/' . $docUuid, ['_method' => 'PUT', 'statut' => 'récupéré'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(RestitutionRequest::STATUS_COMPLETED, RestitutionRequest::sole()->status);
        $this->assertSame(DeclarationDePerte::STATUS_RESTITUTED, DeclarationDePerte::sole()->status);
    }

    public function test_finder_declines_and_the_owner_is_told_the_structure_takes_over(): void
    {
        $this->commissariatA->update(['latitude' => 14.7160, 'longitude' => -17.4580]);
        $this->declareOnline();
        $docUuid = $this->publish(['contact_consent' => true, 'latitude' => 14.7150, 'longitude' => -17.4590]);
        $uuid    = $this->asUser($this->owner)->postJson("/api/documents/{$docUuid}/restitution")->json('request.uuid');

        $this->asUser($this->finder)->postJson("/api/restitution-requests/{$uuid}/decline", ['reason' => 'Je préfère passer par la police'])
            ->assertOk()->assertJsonPath('request.status', 'declined')->assertJsonPath('request.other_party.phone', null);

        Bus::assertDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221771112233'
            && str_contains($job->message, 'Commissariat A va récupérer le document'));
        $this->assertTrue(CollectionRequest::sole()->transitions()->where('reason', 'like', 'Remise directe impossible%')->exists());
    }

    public function test_without_answer_the_request_expires(): void
    {
        $this->declareOnline();
        $docUuid = $this->publish();
        $uuid    = $this->asUser($this->owner)->postJson("/api/documents/{$docUuid}/restitution")->json('request.uuid');

        $this->travel(49)->hours();
        $this->artisan('doctrack:expire-restitution-requests')->assertSuccessful();

        $this->assertSame(RestitutionRequest::STATUS_EXPIRED, RestitutionRequest::sole()->status);
        Notification::assertSentTo($this->owner, RestitutionUpdateNotification::class, fn ($n) => $n->event === RestitutionUpdateNotification::EXPIRED);
        $this->asUser($this->finder)->postJson("/api/restitution-requests/{$uuid}/accept")->assertStatus(409);

        // Une nouvelle demande est possible.
        $this->asUser($this->owner)->postJson("/api/documents/{$docUuid}/restitution")->assertCreated();
    }

    public function test_owner_can_withdraw_his_request(): void
    {
        $this->declareOnline();
        $docUuid = $this->publish();
        $uuid    = $this->asUser($this->owner)->postJson("/api/documents/{$docUuid}/restitution")->json('request.uuid');

        $this->asUser($this->finder)->postJson("/api/restitution-requests/{$uuid}/cancel")->assertForbidden();
        $this->asUser($this->owner)->postJson("/api/restitution-requests/{$uuid}/cancel")->assertOk();
        $this->assertSame(RestitutionRequest::STATUS_CANCELLED, RestitutionRequest::sole()->status);
    }

    public function test_request_is_refused_without_a_confirmed_match(): void
    {
        $docUuid = $this->publish();

        $this->asUser($this->owner)->postJson("/api/documents/{$docUuid}/restitution")
            ->assertForbidden()
            ->assertJsonPath('code', 'no_confirmed_match');

        Notification::assertNothingSentTo($this->finder);
        Bus::assertNotDispatched(SendSmsJob::class, fn ($job) => $job->phone === '+221784445566');
    }

    public function test_someone_else_cannot_use_the_owner_confirmation(): void
    {
        $this->declareOnline();
        $docUuid = $this->publish();

        $this->asUser($this->citizen())->postJson("/api/documents/{$docUuid}/restitution")
            ->assertForbidden()->assertJsonPath('code', 'no_confirmed_match');
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

        $this->asUser($this->owner)->postJson("/api/documents/{$docUuid}/restitution")->assertCreated();

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

        // Le trouveur ne l'a plus : la demande en ligne renvoie vers le guichet.
        $this->asUser($this->owner)->postJson("/api/documents/{$docUuid}/restitution")
            ->assertStatus(409)->assertJsonPath('custody.name', 'Commissariat A');
    }
}
