<?php

namespace Tests\Feature\Declarations;

use App\Models\CertificatDePerte;
use App\Models\CitizenProfile;
use App\Models\DeclarationDePerte;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Organizations\OrganizationTestCase;

class CounterDeclarationTest extends OrganizationTestCase
{
    private DocumentType $cni;
    private DocumentType $permis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cni    = DocumentType::factory()->create(['TypeName' => 'Carte nationale d\'identité']);
        $this->permis = DocumentType::factory()->create(['TypeName' => 'Permis de conduire']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'citizen' => [
                'first_name' => 'Awa',
                'last_name'  => 'Ndiaye',
                'birth_date' => '1990-05-12',
                'phone'      => '77 123 45 67',
            ],
            'lost_at'       => now()->subDays(2)->toDateString(),
            'lost_place'    => 'Marché HLM',
            'circumstances' => 'Sac volé',
            'items' => [
                ['document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye', 'birth_date' => '1990-05-12', 'doc_number' => '1 234 5678 90123'],
                ['document_type_id' => $this->permis->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye', 'doc_number' => 'SN-998877'],
            ],
        ], $overrides);
    }

    private function declare(User $agent, array $overrides = [])
    {
        return $this->asUser($agent)->postJson('/api/v2/declarations', $this->payload($overrides));
    }

    public function test_agent_records_a_multi_document_declaration_for_a_victim_without_account(): void
    {
        $agent = $this->agent($this->commissariatA);

        $response = $this->declare($agent)->assertCreated();

        $this->assertMatchesRegularExpression('/^DT-\d{4}-\d{6}$/', $response->json('reference'));
        $response->assertJsonPath('channel', 'counter')
            ->assertJsonPath('status', 'registered')
            ->assertJsonPath('organization.uuid', $this->commissariatA->uuid)
            ->assertJsonPath('recorded_by.uuid', $agent->uuid)
            ->assertJsonPath('citizen.has_account', false)
            ->assertJsonPath('items.0.doc_number', '1234567890123')
            ->assertJsonPath('items.1.doc_number', 'SN998877')
            ->assertJsonPath('certificate.kind', 'official');

        $this->assertMatchesRegularExpression('/^CP-\d{4}-\d{6}$/', $response->json('certificate.number'));

        $declaration = DeclarationDePerte::where('reference', $response->json('reference'))->first();
        $this->assertNull($declaration->user_id);
        $this->assertCount(2, $declaration->items);

        // Chaîne de traçabilité complète.
        $this->assertSame($this->police->id, $declaration->organization->institution()->id);

        // Numéros jamais stockés en clair.
        $this->assertNull($declaration->DocIdentification);
        $raw = DB::table('declaration_items')->where('declaration_de_perte_id', $declaration->id)->pluck('doc_number_encrypted')->implode(' ');
        $this->assertStringNotContainsString('1234567890123', $raw);
        $this->assertNotNull($declaration->items->first()->doc_number_hash);

        // Certificat PDF sur disque privé.
        Storage::disk('local')->assertExists($declaration->certificat->pdf_path);
        $this->asUser($agent)->get('/api/v2/declarations/' . $declaration->uuid . '/certificate')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->get($response->json('certificate.pdf_url'))->assertOk();
    }

    public function test_references_are_sequential_and_unique(): void
    {
        $agent = $this->agent($this->commissariatA);

        $refs = collect(range(1, 3))->map(fn () => $this->declare($agent)->json('reference'));

        $this->assertSame(3, $refs->unique()->count());
        $this->assertStringEndsWith('000003', $refs->last());
    }

    public function test_existing_victim_is_reused_and_duplicates_are_refused(): void
    {
        $agent = $this->agent($this->commissariatA);
        $this->declare($agent)->assertCreated();
        $this->declare($agent)->assertCreated(); // même téléphone + nom → même profil

        $this->assertSame(1, CitizenProfile::count());

        $this->asUser($agent)->postJson('/api/v2/citizens', [
            'first_name' => 'Awa', 'last_name' => 'NDIAYE', 'phone' => '+221771234567',
        ])->assertStatus(409)->assertJsonPath('existing.first_name', 'Awa');
    }

    public function test_victim_search_is_narrow(): void
    {
        $agent = $this->agent($this->commissariatA);
        $this->declare($agent);

        $this->asUser($agent)->getJson('/api/v2/citizens?phone=771234567')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.phone_masked', '•••••••••4567');

        // Nom seul : refusé (pas de fouille par nom).
        $this->asUser($agent)->getJson('/api/v2/citizens?last_name=Ndiaye')->assertUnprocessable();

        $this->asUser($agent)->getJson('/api/v2/citizens?last_name=ndiaye&first_name=awa&birth_date=1990-05-12')
            ->assertOk()->assertJsonCount(1);

        $this->asUser($this->citizen())->getJson('/api/v2/citizens?phone=771234567')->assertForbidden();
    }

    public function test_victim_with_an_account_sees_the_declaration_but_cannot_delete_it(): void
    {
        $account = $this->citizen(['LastName' => 'Ndiaye', 'Phone' => '+221771234567']);

        $response = $this->declare($this->agent($this->commissariatA))->assertCreated()
            ->assertJsonPath('citizen.has_account', true);

        $uuid = $response->json('uuid');
        $certificatUuid = DeclarationDePerte::where('uuid', $uuid)->first()->certificat->uuid;

        $this->asUser($account)->getJson('/api/my-declarations')->assertOk()->assertJsonPath('data.0.uuid', $uuid);
        $this->asUser($account)->get('/api/certificats/' . $certificatUuid . '/voir')->assertOk();
        $this->asUser($account)->deleteJson('/api/declarations/' . $uuid)->assertForbidden();
    }

    public function test_declarations_are_isolated_between_structures_and_institutions(): void
    {
        $uuid = $this->declare($this->agent($this->commissariatA))->json('uuid');

        $this->asUser($this->agent($this->commissariatB))->getJson('/api/v2/declarations/' . $uuid)->assertForbidden();
        $this->asUser($this->agent($this->brigadeZ))->getJson('/api/v2/declarations/' . $uuid)->assertForbidden();
        $this->asUser($this->institutionAdmin($this->gendarmerie))->getJson('/api/v2/declarations/' . $uuid)->assertForbidden();

        $this->asUser($this->institutionAdmin($this->police))->getJson('/api/v2/declarations/' . $uuid)->assertOk();
        $this->asUser($this->admin())->getJson('/api/v2/declarations/' . $uuid)->assertOk();

        // Listes : chacun son périmètre.
        $this->declare($this->agent($this->commissariatB));
        $this->assertSame(1, $this->asUser($this->manager($this->commissariatA))->getJson('/api/v2/declarations')->json('total'));
        $this->assertSame(2, $this->asUser($this->institutionAdmin($this->police))->getJson('/api/v2/declarations')->json('total'));
        $this->assertSame(0, $this->asUser($this->institutionAdmin($this->gendarmerie))->getJson('/api/v2/declarations')->json('total'));
    }

    public function test_list_masks_numbers_and_detail_view_is_audited(): void
    {
        $agent = $this->agent($this->commissariatA);
        $uuid  = $this->declare($agent)->json('uuid');

        $item = $this->asUser($agent)->getJson('/api/v2/declarations')->json('data.0.items.0');
        $this->assertSame('••••0123', $item['doc_number']);

        $this->asUser($agent)->getJson('/api/v2/declarations/' . $uuid)->assertOk();

        $log = Activity::where('description', 'Consultation d\'une déclaration')->latest('id')->first();
        $this->assertSame($agent->id, $log->causer_id);
        $this->assertSame($this->commissariatA->id, $log->organization_id);
    }

    public function test_only_structure_staff_record_at_the_counter(): void
    {
        $this->declare($this->institutionAdmin($this->police))->assertForbidden();
        $this->declare($this->admin())->assertForbidden();
        $this->declare($this->citizen())->assertForbidden();
    }

    public function test_validation_requires_documents_and_known_types(): void
    {
        $agent = $this->agent($this->commissariatA);

        $this->asUser($agent)->postJson('/api/v2/declarations', ['citizen' => $this->payload()['citizen'], 'items' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->declare($agent, ['items' => [['document_type_id' => 9999, 'first_name' => 'a', 'last_name' => 'b']]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.document_type_id');
    }

    public function test_cancellation_rules_and_certificate_revocation(): void
    {
        $author    = $this->agent($this->commissariatA);
        $colleague = $this->agent($this->commissariatA);
        $manager   = $this->manager($this->commissariatA);

        $uuid = $this->declare($author)->json('uuid');

        $this->asUser($colleague)->postJson("/api/v2/declarations/{$uuid}/cancel", ['reason' => 'Erreur de saisie'])->assertForbidden();
        $this->asUser($author)->postJson("/api/v2/declarations/{$uuid}/cancel", ['reason' => 'Erreur de saisie'])
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->asUser($manager)->postJson("/api/v2/declarations/{$uuid}/cancel", ['reason' => 'Encore'])->assertStatus(409);

        $cert = DeclarationDePerte::where('uuid', $uuid)->first()->certificat;
        $this->assertTrue($cert->isRevoked());
        $this->getJson('/api/v2/verify/' . $cert->verification_code)->assertOk()->assertJsonPath('status', 'revoked');
    }

    public function test_author_cannot_cancel_after_the_window_but_manager_can(): void
    {
        $author = $this->agent($this->commissariatA);
        $uuid   = $this->declare($author)->json('uuid');
        DeclarationDePerte::where('uuid', $uuid)->update(['created_at' => now()->subDays(2)]);

        $this->asUser($author)->postJson("/api/v2/declarations/{$uuid}/cancel", ['reason' => 'Trop tard'])->assertForbidden();
        $this->asUser($this->manager($this->commissariatA))->postJson("/api/v2/declarations/{$uuid}/cancel", ['reason' => 'Doublon constaté'])->assertOk();
    }

    public function test_public_verification_proves_authenticity_without_personal_data(): void
    {
        $response = $this->declare($this->agent($this->commissariatA));
        $cert     = CertificatDePerte::where('certificat_number', $response->json('certificate.number'))->first();
        $code     = $response->json('certificate.verification_code'); // format XXXX-XXXX-XXXX

        $json = $this->getJson('/api/v2/verify/' . $code)->assertOk()
            ->assertJsonPath('status', 'valid')
            ->assertJsonPath('kind', 'official')
            ->assertJsonPath('issued_by.structure', 'Commissariat A')
            ->assertJsonPath('issued_by.institution', 'Police nationale')
            ->assertJsonPath('holder_initials', 'A. N.');

        $content = $json->getContent();
        $this->assertStringNotContainsString('Ndiaye', $content);
        $this->assertStringNotContainsString('1234567890123', $content);

        $this->get('/verifier/' . $cert->verification_code)->assertOk()->assertSee('Certificat authentique');
        $this->get('/verifier/ZZZZZZZZZZZZ')->assertNotFound()->assertSee('introuvable');
        $this->getJson('/api/v2/verify/ZZZZZZZZZZZZ')->assertNotFound();
    }

    public function test_online_declaration_gets_reference_profile_and_verifiable_receipt(): void
    {
        $citizen = $this->citizen();

        $data = $this->asUser($citizen)->postJson('/api/declarations', [
            'Title'             => 'Perte CNI',
            'FirstNameInDoc'    => 'Moussa',
            'LastNameInDoc'     => 'Diop',
            'DocIdentification' => '1 111 2222 33333',
            'document_type_id'  => $this->cni->id,
        ])->assertCreated()->json('data');

        $declaration = DeclarationDePerte::where('uuid', $data['declaration']['uuid'])->first();
        $this->assertSame('online', $declaration->channel);
        $this->assertMatchesRegularExpression('/^DT-\d{4}-\d{6}$/', $declaration->reference);
        $this->assertSame($citizen->id, $declaration->citizenProfile->user_id);
        $this->assertNotNull($declaration->doc_number_hash);

        $this->getJson('/api/v2/verify/' . $declaration->certificat->verification_code)
            ->assertOk()->assertJsonPath('kind', 'receipt');
    }
}
