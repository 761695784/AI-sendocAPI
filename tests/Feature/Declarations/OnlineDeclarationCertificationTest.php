<?php

namespace Tests\Feature\Declarations;

use App\Models\CertificatDePerte;
use App\Models\DeclarationDePerte;
use App\Models\DocumentType;
use App\Models\User;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Passerelle : déclaration faite en ligne (récépissé) → certificat de perte
 * délivré au guichet de n'importe quelle structure habilitée.
 */
class OnlineDeclarationCertificationTest extends OrganizationTestCase
{
    private DocumentType $cni;
    private User $citizenAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cni = DocumentType::factory()->create(['TypeName' => 'Carte nationale d\'identité']);
        $this->citizenAccount = $this->citizen(['FirstName' => 'Awa', 'LastName' => 'Ndiaye', 'Phone' => '+221771234567']);
    }

    private function declareOnline(array $overrides = []): DeclarationDePerte
    {
        $response = $this->asUser($this->citizenAccount)->postJson('/api/declarations', $overrides + [
            'Title'             => 'Perte de ma carte',
            'FirstNameInDoc'    => 'Awa',
            'LastNameInDoc'     => 'Ndiaye',
            'document_type_id'  => $this->cni->id,
            'DocIdentification' => '1 234 5678 90123',
            'lost_place'        => 'Marché HLM',
            'lost_at'           => now()->subDays(3)->toDateString(),
        ])->assertCreated();

        return DeclarationDePerte::where('uuid', $response->json('data.declaration.uuid'))->firstOrFail();
    }

    private function certifyPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'person' => [
                'first_name' => 'Awa',
                'last_name'  => 'Ndiaye',
                'birth_date' => '1990-05-12',
                'id_type'    => 'passeport',
                'id_number'  => 'A01234567',
            ],
            'checks'        => ['identity_photo_matches' => true],
            'circumstances' => 'Sac oublié dans un taxi',
        ], $overrides);
    }

    public function test_online_declaration_gets_a_receipt_not_a_certificate(): void
    {
        $declaration = $this->declareOnline();

        $this->assertSame(DeclarationDePerte::CHANNEL_ONLINE, $declaration->channel);
        $this->assertNull($declaration->organization_id);
        $this->assertSame('Marché HLM', $declaration->lost_place);
        $this->assertNotNull($declaration->lost_at);

        $receipt = $declaration->receipt;
        $this->assertSame(CertificatDePerte::KIND_RECEIPT, $receipt->kind);
        $this->assertMatchesRegularExpression('/^REC-\d{8}-\d{5}$/', $receipt->certificat_number);
        $this->assertNull($declaration->officialCertificate);

        $this->getJson('/api/v2/verify/' . $receipt->verification_code)->assertOk()
            ->assertJsonPath('kind', 'receipt')
            ->assertJsonPath('certified', null);
    }

    public function test_agent_finds_the_declaration_by_reference_or_receipt_code_with_a_minimal_summary(): void
    {
        $declaration = $this->declareOnline();
        $agent = $this->agent($this->commissariatA);

        $this->asUser($agent)->getJson('/api/v2/online-declarations/lookup?q=' . strtolower($declaration->reference))->assertOk()
            ->assertJsonPath('uuid', $declaration->uuid)
            ->assertJsonPath('holder_initials', 'A. N.')
            ->assertJsonPath('can_certify', true)
            ->assertJsonMissingPath('FirstNameInDoc')
            ->assertJsonMissingPath('DocIdentification');

        // Lien complet du QR code du récépissé.
        $url = $declaration->receipt->verificationUrl();
        $this->asUser($agent)->getJson('/api/v2/online-declarations/lookup?q=' . urlencode($url))->assertOk()
            ->assertJsonPath('uuid', $declaration->uuid);

        $this->asUser($agent)->getJson('/api/v2/online-declarations/lookup?q=DT-1999-000001')->assertNotFound();

        // Un citoyen ne peut pas chercher dans les déclarations des autres.
        $this->asUser($this->citizen())->getJson('/api/v2/online-declarations/lookup?q=' . $declaration->reference)->assertForbidden();
    }

    public function test_any_authorized_structure_certifies_and_issues_the_official_certificate(): void
    {
        $declaration = $this->declareOnline();
        $agent = $this->agent($this->brigadeZ); // gendarmerie : n'importe quelle structure habilitée

        $response = $this->asUser($agent)
            ->postJson('/api/v2/online-declarations/' . $declaration->uuid . '/certify', $this->certifyPayload())
            ->assertCreated()
            ->assertJsonPath('channel', 'online')
            ->assertJsonPath('organization.uuid', $this->brigadeZ->uuid)
            ->assertJsonPath('certificate.kind', 'official')
            ->assertJsonPath('certified.person.id_last4', '4567');

        $this->assertMatchesRegularExpression('/^CP-\d{4}-\d{6}$/', $response->json('certificate.number'));

        $declaration->refresh();
        $this->assertTrue($declaration->isCertified());
        $this->assertSame($agent->id, $declaration->certified_by_user_id);
        $this->assertSame('Sac oublié dans un taxi', $declaration->circumstances);
        // Le numéro de la pièce présentée n'est jamais conservé en clair.
        $this->assertNull(DeclarationDePerte::where('certification_id_last4', 'A01234567')->first());

        // Une seule déclaration (pas de doublon), deux documents : récépissé + certificat.
        $this->assertSame(1, DeclarationDePerte::count());
        $this->assertSame(2, $declaration->certificats()->count());
        $this->assertSame(CertificatDePerte::KIND_OFFICIAL, $declaration->certificat->kind);
        $this->assertSame($this->brigadeZ->id, $declaration->officialCertificate->issued_by_organization_id);

        // Le récépissé reste valable et indique la certification.
        $this->getJson('/api/v2/verify/' . $declaration->receipt->verification_code)->assertOk()
            ->assertJsonPath('status', 'valid')
            ->assertJsonPath('certified.structure', 'Brigade Z');

        // Les autres agents de la même structure voient le dossier ; pas ceux d'une autre structure.
        $colleague = $this->agent($this->brigadeZ);
        $this->asUser($colleague)->getJson('/api/v2/declarations')->assertOk()->assertJsonPath('data.0.uuid', $declaration->uuid);
        $this->asUser($colleague)->getJson('/api/v2/declarations/' . $declaration->uuid)->assertOk();
        $this->asUser($this->agent($this->commissariatA))->getJson('/api/v2/declarations/' . $declaration->uuid)->assertForbidden();

        // Le citoyen voit son certificat mais ne peut plus supprimer la déclaration.
        $this->asUser($this->citizenAccount)->getJson('/api/my-declarations')->assertOk()
            ->assertJsonPath('data.0.official_certificate.kind', 'official')
            ->assertJsonPath('data.0.receipt.kind', 'receipt');
        $this->asUser($this->citizenAccount)->deleteJson('/api/declarations/' . $declaration->uuid)->assertForbidden();
    }

    public function test_a_declaration_is_certified_only_once(): void
    {
        $declaration = $this->declareOnline();
        $agent = $this->agent($this->commissariatA);

        $this->asUser($agent)->postJson('/api/v2/online-declarations/' . $declaration->uuid . '/certify', $this->certifyPayload())->assertCreated();
        $this->asUser($this->agent($this->commissariatB))
            ->postJson('/api/v2/online-declarations/' . $declaration->uuid . '/certify', $this->certifyPayload())
            ->assertStatus(409);

        $this->assertSame(1, $declaration->certificats()->where('kind', CertificatDePerte::KIND_OFFICIAL)->count());
    }

    public function test_identity_checks_are_required(): void
    {
        $declaration = $this->declareOnline();
        $agent = $this->agent($this->commissariatA);
        $url = '/api/v2/online-declarations/' . $declaration->uuid . '/certify';

        // Photo non vérifiée.
        $this->asUser($agent)->postJson($url, $this->certifyPayload(['checks' => ['identity_photo_matches' => false]]))
            ->assertUnprocessable()->assertJsonValidationErrors('checks.identity_photo_matches');

        // Pièce absente.
        $payload = $this->certifyPayload();
        unset($payload['person']['id_number']);
        $this->asUser($agent)->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('person.id_number');

        // Nom différent : refusé pour un agent, dérogation motivée possible pour un responsable.
        $other = $this->certifyPayload(['person' => ['first_name' => 'Moussa', 'last_name' => 'Diop']]);
        $this->asUser($agent)->postJson($url, $other)->assertUnprocessable()->assertJsonValidationErrors('person.last_name');
        $this->asUser($agent)->postJson($url, $other + ['name_override_reason' => 'Procuration notariée'])
            ->assertUnprocessable()->assertJsonValidationErrors('name_override_reason');

        $this->assertFalse($declaration->fresh()->isCertified());

        $this->asUser($this->manager($this->commissariatA))
            ->postJson($url, $other + ['name_override_reason' => 'Nom d\'épouse vérifié sur l\'extrait de mariage'])
            ->assertCreated();
    }

    public function test_counter_declarations_cannot_go_through_the_online_certification(): void
    {
        $agent = $this->agent($this->commissariatA);
        $counter = $this->asUser($agent)->postJson('/api/v2/declarations', [
            'citizen' => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 123 45 67'],
            'items'   => [['document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye']],
        ])->assertCreated();

        $this->asUser($agent)->postJson('/api/v2/online-declarations/' . $counter->json('uuid') . '/certify', $this->certifyPayload())
            ->assertUnprocessable();
    }
}
