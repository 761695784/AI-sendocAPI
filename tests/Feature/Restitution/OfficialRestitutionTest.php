<?php

namespace Tests\Feature\Restitution;

use App\Jobs\SendSmsJob;
use App\Models\DeclarationDePerte;
use App\Models\DeclarationItem;
use App\Models\Document;
use App\Models\DocumentMatch;
use App\Models\DocumentType;
use App\Models\Restitution;
use App\Support\PrivateFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Phase 5 : restitution officielle au guichet.
 * Scénario de base : Awa Ndiaye déclare sa CNI au commissariat A ;
 * un trouveur la dépose au commissariat B (même numéro → correspondance confirmée).
 */
class OfficialRestitutionTest extends OrganizationTestCase
{
    private DocumentType $cni;
    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
        Bus::fake([SendSmsJob::class]);

        $this->cni = DocumentType::factory()->create(['TypeName' => 'Carte nationale d\'identité']);

        $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/declarations', [
            'citizen' => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 123 45 67', 'birth_date' => '1990-05-12'],
            'items'   => [['document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye',
                'birth_date' => '1990-05-12', 'doc_number' => '1234567890123']],
        ])->assertCreated();

        $uuid = $this->asUser($this->agent($this->commissariatB))->postJson('/api/v2/found-documents', [
            'document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye',
            'birth_date' => '1990-05-12', 'doc_number' => '1234567890123',
            'finder' => ['first_name' => 'Ibrahima', 'last_name' => 'Fall', 'phone' => '76 111 22 33'],
        ])->assertCreated()->json('uuid');

        $this->document = Document::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(DocumentMatch::STATUS_CONFIRMED, DocumentMatch::sole()->status);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'recipient_type' => 'owner',
            'owner'  => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'birth_date' => '1990-05-12',
                'id_type' => 'passeport', 'id_number' => 'A01234567', 'phone' => '77 123 45 67'],
            'checks' => ['identity_photo_matches' => true],
        ], $overrides);
    }

    private function restitute($user, ?array $payload = null)
    {
        return $this->asUser($user)->postJson("/api/v2/found-documents/{$this->document->uuid}/restitution", $payload ?? $this->payload());
    }

    public function test_agent_prepares_the_handover(): void
    {
        $this->asUser($this->agent($this->commissariatB))->getJson("/api/v2/found-documents/{$this->document->uuid}/restitution")
            ->assertOk()
            ->assertJsonPath('can_restitute', true)
            ->assertJsonPath('proxy_allowed', true)
            ->assertJsonPath('confirmed_match.declaration_reference', DeclarationDePerte::sole()->reference)
            ->assertJsonPath('confirmed_match.declared_by', 'Awa Ndiaye');

        $this->asUser($this->agent($this->commissariatA))->getJson("/api/v2/found-documents/{$this->document->uuid}/restitution")->assertForbidden();
    }

    public function test_owner_receives_the_document_and_everything_is_closed(): void
    {
        $agentB   = $this->agent($this->commissariatB);
        $response = $this->restitute($agentB)->assertCreated()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('recipient_type', 'owner')
            ->assertJsonPath('owner.id_last4', '4567')
            ->assertJsonPath('checks.without_prior_declaration', false);

        $this->assertMatchesRegularExpression('/^RS-\d{4}-\d{6}$/', $response->json('reference'));

        $r = Restitution::sole();
        $this->assertTrue(PrivateFiles::exists($r->pdf_path));

        // Aucune pièce d'identité en clair.
        $raw = (array) DB::table('restitutions')->first();
        $this->assertStringNotContainsString('A01234567', json_encode($raw));

        // Document, pièce, déclaration : restitués.
        $this->assertSame(Document::LIFECYCLE_RESTITUTED, $this->document->fresh()->lifecycle_status);
        $this->assertSame('récupéré', $this->document->fresh()->statut);
        $this->assertSame(DeclarationDePerte::STATUS_RESTITUTED, DeclarationDePerte::sole()->status);
        $this->assertSame(DeclarationItem::STATUS_RESTITUTED, DeclarationItem::sole()->status);

        // Reçu SMS au propriétaire, remerciement au trouveur.
        Bus::assertDispatched(SendSmsJob::class, fn ($j) => str_contains((string) $j->phone, '771234567') && str_contains($j->message, $r->reference));
        Bus::assertDispatched(SendSmsJob::class, fn ($j) => str_contains((string) $j->phone, '761112233') && str_contains($j->message, 'rendu à son propriétaire'));

        // Pas deux fois.
        $this->restitute($agentB)->assertUnprocessable()->assertJsonValidationErrors('document');

        // Décharge : agent de la structure oui, autre structure non.
        $this->asUser($agentB)->get("/api/v2/restitutions/{$r->uuid}/discharge")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->asUser($this->agent($this->commissariatA))->getJson("/api/v2/restitutions/{$r->uuid}/discharge")->assertForbidden();

        // Vérification publique par le QR code.
        $this->getJson('/api/v2/verify/' . $r->verification_code)->assertOk()
            ->assertJsonPath('type', 'restitution')
            ->assertJsonPath('status', 'valid')
            ->assertJsonPath('certificate_number', $r->reference)
            ->assertJsonPath('holder_initials', 'A. N.');
        $this->get('/verifier/' . $r->verification_code)->assertOk()->assertSee('Décharge authentique');
    }

    public function test_agent_must_certify_the_photo(): void
    {
        $this->restitute($this->agent($this->commissariatB), $this->payload(['checks' => ['identity_photo_matches' => false]]))
            ->assertUnprocessable()->assertJsonValidationErrors('checks.identity_photo_matches');
        $this->assertSame(0, Restitution::count());
    }

    public function test_name_mismatch_needs_a_manager_derogation(): void
    {
        $wrong = $this->payload(['owner' => ['first_name' => 'Fatou', 'last_name' => 'Diallo']]);

        $this->restitute($this->agent($this->commissariatB), $wrong)->assertUnprocessable()->assertJsonValidationErrors('owner.last_name');
        $this->restitute($this->agent($this->commissariatB), $wrong + ['name_override_reason' => 'Nom d\'épouse'])
            ->assertUnprocessable()->assertJsonValidationErrors('name_override_reason');

        $this->restitute($this->manager($this->commissariatB), $wrong + ['name_override_reason' => 'Nom d\'épouse, acte de mariage présenté'])
            ->assertCreated()->assertJsonPath('name_override', 'Nom d\'épouse, acte de mariage présenté');
    }

    public function test_spelling_variants_are_accepted_without_derogation(): void
    {
        $this->restitute($this->agent($this->commissariatB), $this->payload(['owner' => ['last_name' => 'Njaay']]))->assertCreated();
    }

    public function test_different_birth_date_is_always_refused(): void
    {
        $this->restitute($this->manager($this->commissariatB), $this->payload([
            'owner' => ['birth_date' => '1985-01-01'], 'name_override_reason' => 'Tentative de dérogation',
        ]))->assertUnprocessable()->assertJsonValidationErrors('owner.birth_date');
    }

    public function test_proxy_with_power_of_attorney(): void
    {
        $agentB = $this->agent($this->commissariatB);
        $proxy  = $this->payload([
            'recipient_type' => 'proxy',
            'owner'  => ['id_type' => null, 'id_number' => null],
            'proxy'  => ['first_name' => 'Mamadou', 'last_name' => 'Ndiaye', 'id_type' => 'cni', 'id_number' => '2987654321098',
                'relationship' => 'Frère', 'power_of_attorney_ref' => 'Procuration du 02/10 légalisée'],
            'checks' => ['identity_photo_matches' => true],
        ]);

        // Procuration non vérifiée → refus.
        $this->restitute($agentB, $proxy)->assertUnprocessable()->assertJsonValidationErrors('checks.power_of_attorney_verified');

        // Type de pièce qui interdit la procuration → refus.
        $this->cni->update(['allows_proxy' => false]);
        $proxy['checks']['power_of_attorney_verified'] = true;
        $this->restitute($agentB, $proxy)->assertUnprocessable()->assertJsonValidationErrors('recipient_type');

        $this->cni->update(['allows_proxy' => true]);
        $this->restitute($agentB, $proxy)->assertCreated()
            ->assertJsonPath('recipient_type', 'proxy')
            ->assertJsonPath('proxy.name', 'Mamadou Ndiaye')
            ->assertJsonPath('proxy.id_last4', '1098')
            ->assertJsonPath('checks.power_of_attorney_verified', true);
    }

    public function test_only_the_custody_structure_can_hand_it_over(): void
    {
        $this->restitute($this->agent($this->commissariatA))->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->restitute($this->citizen())->assertForbidden();
        $this->restitute($this->institutionAdmin($this->police))->assertForbidden(); // pas de guichet
    }

    public function test_restitution_without_prior_declaration(): void
    {
        $uuid = $this->asUser($this->agent($this->commissariatB))->postJson('/api/v2/found-documents', [
            'document_type_id' => $this->cni->id, 'first_name' => 'Ousmane', 'last_name' => 'Sow',
        ])->json('uuid');

        $this->asUser($this->agent($this->commissariatB))->postJson("/api/v2/found-documents/{$uuid}/restitution", $this->payload([
            'owner' => ['first_name' => 'Ousmane', 'last_name' => 'Sow', 'birth_date' => null],
        ]))->assertCreated()->assertJsonPath('checks.without_prior_declaration', true);
    }

    public function test_manager_voids_an_erroneous_handover_within_the_window(): void
    {
        $uuid = $this->restitute($this->agent($this->commissariatB))->json('uuid');
        $r    = Restitution::sole();

        $this->asUser($this->agent($this->commissariatB))->postJson("/api/v2/restitutions/{$uuid}/void", ['reason' => 'Erreur de saisie'])->assertForbidden();
        $this->asUser($this->manager($this->commissariatB))->postJson("/api/v2/restitutions/{$uuid}/void", ['reason' => 'Mauvais document remis'])
            ->assertOk()->assertJsonPath('status', 'voided');

        $this->assertSame(Document::LIFECYCLE_MATCHED, $this->document->fresh()->lifecycle_status);
        $this->assertSame(DeclarationDePerte::STATUS_MATCHED, DeclarationDePerte::sole()->status);
        $this->assertSame(DeclarationItem::STATUS_FOUND, DeclarationItem::sole()->status);
        $this->getJson('/api/v2/verify/' . $r->verification_code)->assertJsonPath('status', 'revoked');

        $this->asUser($this->manager($this->commissariatB))->postJson("/api/v2/restitutions/{$uuid}/void", ['reason' => 'Encore une fois'])->assertUnprocessable();

        // Le document peut de nouveau être remis (à la bonne personne).
        $this->restitute($this->agent($this->commissariatB))->assertCreated();
    }

    public function test_void_window_is_limited(): void
    {
        $uuid = $this->restitute($this->agent($this->commissariatB))->json('uuid');
        $this->travel(73)->hours();

        $this->asUser($this->manager($this->commissariatB))->postJson("/api/v2/restitutions/{$uuid}/void", ['reason' => 'Trop tard pour annuler'])
            ->assertUnprocessable();
    }

    public function test_signed_discharge_scan_and_listing_scope(): void
    {
        $agentB = $this->agent($this->commissariatB);
        $uuid   = $this->restitute($agentB)->json('uuid');

        $this->asUser($agentB)->post("/api/v2/restitutions/{$uuid}/signed-scan", [
            'file' => UploadedFile::fake()->create('decharge.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('signed_scan', true);
        $this->assertTrue(PrivateFiles::exists(Restitution::sole()->signed_scan_path));

        $this->asUser($this->manager($this->commissariatB))->getJson('/api/v2/restitutions')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->manager($this->commissariatA))->getJson('/api/v2/restitutions')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->institutionAdmin($this->police))->getJson('/api/v2/restitutions')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->citizen())->getJson('/api/v2/restitutions')->assertForbidden();

        $this->asUser($agentB)->getJson("/api/v2/restitutions/{$uuid}")->assertOk()
            ->assertJsonPath('history.0.to', 'completed');
    }
}
