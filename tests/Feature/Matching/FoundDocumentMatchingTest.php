<?php

namespace Tests\Feature\Matching;

use App\Jobs\MatchDocumentJob;
use App\Models\DeclarationDePerte;
use App\Models\DeclarationItem;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Documents trouvés en garde + moteur de correspondance + revue par les agents.
 */
class FoundDocumentMatchingTest extends OrganizationTestCase
{
    private DocumentType $cni;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->cni = DocumentType::factory()->create(['TypeName' => 'Carte nationale d\'identité']);
    }

    // ── Aides ──────────────────────────────────────────────────

    private function declareAtCounter(User $agent, array $item = [], array $extra = []): DeclarationDePerte
    {
        $uuid = $this->asUser($agent)->postJson('/api/v2/declarations', array_merge([
            'citizen' => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 123 45 67'],
            'lost_at' => now()->subDays(5)->toDateString(),
            'items'   => [array_merge([
                'document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye',
                'birth_date' => '1990-05-12',
            ], $item)],
        ], $extra))->assertCreated()->json('uuid');

        return DeclarationDePerte::where('uuid', $uuid)->firstOrFail();
    }

    private function deposit(User $agent, array $data = [])
    {
        return $this->asUser($agent)->postJson('/api/v2/found-documents', array_merge([
            'document_type_id' => $this->cni->id,
            'first_name'       => 'Awa',
            'last_name'        => 'Ndiaye',
            'found_place'      => 'Arrêt de bus Liberté 6',
        ], $data));
    }

    private function publishOnline(User $citizen, array $data = [])
    {
        return $this->asUser($citizen)->post('/api/documents', array_merge([
            'image'            => UploadedFile::fake()->image('cni.jpg', 600, 400),
            'OwnerFirstName'   => 'Awa',
            'OwnerLastName'    => 'Ndiaye',
            'Location'         => 'Dakar',
            'document_type_id' => $this->cni->id,
        ], $data), ['Accept' => 'application/json']);
    }

    // ── Dépôt au guichet ──────────────────────────────────────

    public function test_agent_deposits_a_found_document_with_encrypted_number(): void
    {
        $agent = $this->agent($this->commissariatA);

        $response = $this->deposit($agent, [
            'doc_number' => '1 234 5678 90123',
            'finder'     => ['first_name' => 'Moussa', 'last_name' => 'Sarr', 'phone' => '78 000 00 01'],
        ])->assertCreated()
            ->assertJsonPath('found_channel', 'counter')
            ->assertJsonPath('lifecycle_status', 'in_custody')
            ->assertJsonPath('custody.organization.uuid', $this->commissariatA->uuid)
            ->assertJsonPath('doc_number', '1234567890123');

        $this->assertMatchesRegularExpression('/^TR-\d{4}-\d{6}$/', $response->json('reference'));

        $raw = DB::table('documents')->where('uuid', $response->json('uuid'))->first();
        $this->assertNull($raw->DocIdentification);
        $this->assertStringNotContainsString('1234567890123', (string) $raw->doc_number_encrypted);
        $this->assertSame('0123', $raw->doc_number_last4);
        $this->assertNotNull($raw->last_name_key);
    }

    public function test_citizen_and_other_structures_have_no_access_to_custody(): void
    {
        $uuid = $this->deposit($this->agent($this->commissariatA))->json('uuid');

        $this->asUser($this->citizen())->getJson('/api/v2/found-documents')->assertForbidden();
        $this->asUser($this->citizen())->postJson('/api/v2/found-documents', [])->assertForbidden();
        $this->asUser($this->agent($this->brigadeZ))->getJson('/api/v2/found-documents/' . $uuid)->assertForbidden();
        $this->asUser($this->agent($this->brigadeZ))->getJson('/api/v2/found-documents')->assertOk()->assertJsonCount(0, 'data');

        // Le responsable de l'institution voit les dépôts de ses structures.
        $this->asUser($this->institutionAdmin($this->police))->getJson('/api/v2/found-documents')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->institutionAdmin($this->police))->getJson('/api/v2/found-documents/' . $uuid)->assertOk();
    }

    public function test_institution_admin_cannot_deposit_outside_a_structure(): void
    {
        $this->deposit($this->institutionAdmin($this->police))->assertForbidden();
    }

    // ── Correspondances automatiques ──────────────────────────

    public function test_same_number_auto_confirms_across_structures(): void
    {
        $declaration = $this->declareAtCounter($this->agent($this->commissariatA), ['doc_number' => '1234567890123']);

        $uuid = $this->deposit($this->agent($this->commissariatB), [
            'first_name' => 'Awa', 'last_name' => 'Ndiay', 'doc_number' => '1234 5678 90123',
        ])->assertCreated()->json('uuid');

        $match = DocumentMatch::sole();
        $this->assertSame(DocumentMatch::STATUS_CONFIRMED, $match->status);
        $this->assertTrue($match->number_match);
        $this->assertSame($this->commissariatB->id, $match->organization_id); // revue par la structure qui a le document

        $this->assertSame(DeclarationDePerte::STATUS_MATCHED, $declaration->fresh()->status);
        $this->assertSame(DeclarationItem::STATUS_FOUND, $declaration->items()->first()->status);
        $this->assertSame(Document::LIFECYCLE_MATCHED, Document::where('uuid', $uuid)->value('lifecycle_status'));

        // Historique des statuts tracé.
        $this->assertTrue($declaration->transitions()->where('to', DeclarationDePerte::STATUS_MATCHED)->exists());
    }

    public function test_names_only_go_to_the_review_queue_of_the_custody_structure(): void
    {
        $this->declareAtCounter($this->agent($this->commissariatA));
        $this->deposit($this->agent($this->commissariatB), ['first_name' => 'Awa', 'last_name' => 'Njaay']);

        $match = DocumentMatch::sole();
        $this->assertSame(DocumentMatch::STATUS_PENDING_REVIEW, $match->status);
        $this->assertGreaterThanOrEqual(75, $match->score);

        $queue = $this->asUser($this->agent($this->commissariatB))->getJson('/api/v2/matches')->assertOk();
        $queue->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lost_item.last_name', 'Ndiaye')
            ->assertJsonPath('data.0.found_document.last_name', 'Njaay');
        $this->assertArrayHasKey('names', $queue->json('data.0.score_details'));
        $this->assertStringNotContainsString('77 123 45 67', $queue->getContent());
        $this->assertStringNotContainsString('771234567', $queue->getContent());

        // Isolation : ni l'autre commissariat, ni la gendarmerie.
        $this->asUser($this->agent($this->brigadeZ))->getJson('/api/v2/matches')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->agent($this->brigadeZ))->getJson('/api/v2/matches/' . $match->uuid)->assertForbidden();
        $this->asUser($this->agent($this->brigadeZ))->postJson('/api/v2/matches/' . $match->uuid . '/confirm')->assertForbidden();
        $this->asUser($this->citizen())->getJson('/api/v2/matches')->assertForbidden();
    }

    public function test_unrelated_person_is_not_matched(): void
    {
        $this->declareAtCounter($this->agent($this->commissariatA));
        $this->deposit($this->agent($this->commissariatB), ['first_name' => 'Moussa', 'last_name' => 'Diop']);

        $this->assertSame(0, DocumentMatch::count());
    }

    // ── Revue par un agent ────────────────────────────────────

    public function test_confirming_a_match_supersedes_the_competing_ones(): void
    {
        $declaration = $this->declareAtCounter($this->agent($this->commissariatA));
        $agentB      = $this->agent($this->commissariatB);

        $this->deposit($agentB, ['first_name' => 'Awa', 'last_name' => 'Ndiaye']);
        $this->deposit($agentB, ['first_name' => 'Awa', 'last_name' => 'Ndiay']);
        $this->assertSame(2, DocumentMatch::where('status', DocumentMatch::STATUS_PENDING_REVIEW)->count());

        $first = DocumentMatch::orderBy('id')->first();
        $this->asUser($agentB)->postJson('/api/v2/matches/' . $first->uuid . '/confirm', ['note' => 'Photo conforme'])
            ->assertOk()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('review_note', 'Photo conforme');

        $this->assertSame(DocumentMatch::STATUS_SUPERSEDED, DocumentMatch::orderByDesc('id')->first()->status);
        $this->assertSame(DeclarationDePerte::STATUS_MATCHED, $declaration->fresh()->status);
        $this->assertNotNull($first->fresh()->reviewed_by);

        // Décision finale : plus de revue possible.
        $this->asUser($agentB)->postJson('/api/v2/matches/' . $first->uuid . '/reject', ['reason' => 'Changement d\'avis'])->assertForbidden();
    }

    public function test_a_rejected_pair_is_never_proposed_again(): void
    {
        $this->declareAtCounter($this->agent($this->commissariatA));
        $agentB = $this->agent($this->commissariatB);
        $docUuid = $this->deposit($agentB)->json('uuid');

        $match = DocumentMatch::sole();
        $this->asUser($agentB)->postJson('/api/v2/matches/' . $match->uuid . '/reject', ['reason' => 'x'])->assertUnprocessable();
        $this->asUser($agentB)->postJson('/api/v2/matches/' . $match->uuid . '/reject', ['reason' => 'Photo différente'])
            ->assertOk()->assertJsonPath('status', 'rejected');

        MatchDocumentJob::dispatchSync(Document::where('uuid', $docUuid)->value('id'));

        $this->assertSame(1, DocumentMatch::count());
        $this->assertSame(DocumentMatch::STATUS_REJECTED, $match->fresh()->status);
        $this->asUser($agentB)->getJson('/api/v2/matches?status=rejected')->assertJsonCount(1, 'data');
    }

    public function test_cancelling_a_declaration_supersedes_its_open_matches(): void
    {
        $author      = $this->agent($this->commissariatA);
        $declaration = $this->declareAtCounter($author);
        $this->deposit($this->agent($this->commissariatB));

        $this->asUser($author)->postJson("/api/v2/declarations/{$declaration->uuid}/cancel", ['reason' => 'Doublon de saisie'])->assertOk();

        $this->assertSame(DocumentMatch::STATUS_SUPERSEDED, DocumentMatch::sole()->status);
    }

    public function test_declaration_made_after_the_deposit_is_matched_too(): void
    {
        $this->deposit($this->agent($this->commissariatB), ['doc_number' => 'A1234567']);
        $this->assertSame(0, DocumentMatch::count());

        $this->declareAtCounter($this->agent($this->commissariatA), ['doc_number' => 'a1234567']);

        $this->assertSame(DocumentMatch::STATUS_CONFIRMED, DocumentMatch::sole()->status);
    }

    // ── Compatibilité V1 (espace citoyen) ─────────────────────

    public function test_online_declaration_and_online_publication_are_matched(): void
    {
        $owner = $this->citizen();
        $this->asUser($owner)->postJson('/api/declarations', [
            'Title' => 'Perte CNI', 'FirstNameInDoc' => 'Awa', 'LastNameInDoc' => 'Ndiaye',
            'DocIdentification' => '1234567890123', 'document_type_id' => $this->cni->id,
        ])->assertCreated();

        $declaration = DeclarationDePerte::sole();
        $this->assertCount(1, $declaration->items); // pièce V2 créée depuis les colonnes V1

        $this->publishOnline($this->citizen(), ['DocIdentification' => '1234567890123'])->assertCreated();

        $match = DocumentMatch::sole();
        $this->assertSame(DocumentMatch::STATUS_CONFIRMED, $match->status);
        $this->assertNull($match->organization_id); // aucune structure impliquée
    }

    public function test_nobody_is_matched_with_his_own_publication(): void
    {
        $citizen = $this->citizen();
        $this->asUser($citizen)->postJson('/api/declarations', [
            'Title' => 'Perte', 'FirstNameInDoc' => 'Awa', 'LastNameInDoc' => 'Ndiaye', 'document_type_id' => $this->cni->id,
        ])->assertCreated();
        $this->publishOnline($citizen)->assertCreated();

        $this->assertSame(0, DocumentMatch::count());
    }

    public function test_finder_hands_an_online_document_over_to_a_structure(): void
    {
        $this->asUser($this->citizen())->postJson('/api/declarations', [
            'Title' => 'Perte', 'FirstNameInDoc' => 'Awa', 'LastNameInDoc' => 'Ndiaye', 'document_type_id' => $this->cni->id,
        ])->assertCreated();

        $finder  = $this->citizen();
        $docUuid = $this->publishOnline($finder)->assertCreated()->json('document.uuid');
        $match   = DocumentMatch::sole();
        $this->assertNull($match->organization_id);

        $agent = $this->agent($this->commissariatA);
        $this->asUser($agent)->postJson("/api/v2/found-documents/{$docUuid}/take-custody")
            ->assertOk()
            ->assertJsonPath('lifecycle_status', 'in_custody')
            ->assertJsonPath('custody.organization.uuid', $this->commissariatA->uuid);

        $this->assertSame($this->commissariatA->id, $match->fresh()->organization_id);
        $this->asUser($agent)->getJson('/api/v2/matches')->assertJsonCount(1, 'data');

        // Une seconde prise en garde est refusée.
        $this->asUser($this->agent($this->commissariatB))->postJson("/api/v2/found-documents/{$docUuid}/take-custody")->assertForbidden();

        // V1 : plus de remise de particulier à particulier, on renvoie vers le guichet.
        $this->asUser($this->citizen())->postJson("/api/documents/{$docUuid}/restitution")
            ->assertStatus(409)
            ->assertJsonPath('custody.name', 'Commissariat A');
    }

    public function test_counter_documents_in_the_public_listing_hide_the_agent_and_the_number(): void
    {
        $agent = $this->agent($this->commissariatA, ['FirstName' => 'Agent', 'LastName' => 'Secret']);
        $uuid  = $this->deposit($agent, ['doc_number' => '1234567890123'])->json('uuid');

        $public = $this->getJson('/api/documents/' . $uuid)->assertOk();
        $public->assertJsonPath('found_channel', 'counter')
            ->assertJsonPath('custody.name', 'Commissariat A')
            ->assertJsonPath('DocIdentification', '••••0123')
            ->assertJsonPath('user', null);
        $this->assertStringNotContainsString('Secret', $public->getContent());
        $this->assertStringNotContainsString('1234567890123', $public->getContent());

        // Ni modification ni suppression par l'API citoyenne.
        $this->asUser($agent)->deleteJson('/api/documents/' . $uuid)->assertStatus(409);
    }

    public function test_citizen_marking_his_publication_as_returned_closes_the_matches(): void
    {
        $this->asUser($this->citizen())->postJson('/api/declarations', [
            'Title' => 'Perte', 'FirstNameInDoc' => 'Awa', 'LastNameInDoc' => 'Ndiaye', 'document_type_id' => $this->cni->id,
        ])->assertCreated();

        $finder  = $this->citizen();
        $docUuid = $this->publishOnline($finder)->json('document.uuid');
        $this->assertSame(DocumentMatch::STATUS_PENDING_REVIEW, DocumentMatch::sole()->status);

        $this->asUser($finder)->post('/api/documents/' . $docUuid, ['_method' => 'PUT', 'statut' => 'récupéré'], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(Document::LIFECYCLE_RESTITUTED, Document::where('uuid', $docUuid)->value('lifecycle_status'));
        $this->assertSame(DocumentMatch::STATUS_SUPERSEDED, DocumentMatch::sole()->status);
    }
}
