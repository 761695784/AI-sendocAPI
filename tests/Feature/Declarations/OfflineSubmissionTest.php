<?php

namespace Tests\Feature\Declarations;

use App\Models\CertificatDePerte;
use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Envois préparés hors connexion par l'application : l'appareil attribue un identifiant
 * (client_uuid) et note l'heure de saisie ; un même envoi reçu deux fois ne crée qu'une fiche.
 */
class OfflineSubmissionTest extends TestCase
{
    private function declaration(array $extra = []): array
    {
        return array_merge([
            'Title'            => 'Perte — CNI',
            'FirstNameInDoc'   => 'Awa',
            'LastNameInDoc'    => 'Ndiaye',
            'document_type_id' => DocumentType::factory()->create()->id,
        ], $extra);
    }

    public function test_the_same_offline_declaration_sent_twice_is_recorded_once(): void
    {
        $citizen = $this->citizen();
        $payload = $this->declaration(['client_uuid' => (string) Str::uuid(), 'client_created_at' => now()->subHours(3)->toIso8601String()]);

        $first = $this->asUser($citizen)->postJson('/api/declarations', $payload)->assertCreated();
        $second = $this->asUser($citizen)->postJson('/api/declarations', $payload)->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame(1, DeclarationDePerte::count());
        $this->assertSame(1, CertificatDePerte::count());
        $this->assertSame($first->json('data.declaration.reference'), $second->json('data.declaration.reference'));
        $this->assertSame($first->json('data.certificat.uuid'), $second->json('data.certificat.uuid'));

        $declaration = DeclarationDePerte::sole();
        $this->assertSame($payload['client_uuid'], $declaration->client_uuid);
        $this->assertTrue($declaration->client_created_at->lt(now()->subHours(2)));   // heure de saisie gardée
    }

    public function test_another_user_cannot_reuse_a_client_uuid_to_read_a_declaration(): void
    {
        $uuid = (string) Str::uuid();
        $this->asUser($this->citizen())->postJson('/api/declarations', $this->declaration(['client_uuid' => $uuid]))->assertCreated();

        // Même identifiant envoyé par un autre compte : nouvelle déclaration, rien de l'autre n'est renvoyé.
        $this->asUser($this->citizen())->postJson('/api/declarations', $this->declaration(['client_uuid' => $uuid, 'FirstNameInDoc' => 'Moussa']))
            ->assertCreated()
            ->assertJsonPath('data.declaration.FirstNameInDoc', 'Moussa');
        $this->assertSame(2, DeclarationDePerte::count());
    }

    public function test_device_clock_in_the_future_or_too_old_is_not_trusted(): void
    {
        $citizen = $this->citizen();
        $this->asUser($citizen)->postJson('/api/declarations', $this->declaration(['client_created_at' => now()->addDays(2)->toIso8601String()]))->assertCreated();
        $this->asUser($citizen)->postJson('/api/declarations', $this->declaration(['client_created_at' => now()->subYear()->toIso8601String()]))->assertCreated();

        [$future, $old] = DeclarationDePerte::orderBy('id')->get()->all();
        $this->assertTrue($future->client_created_at->lte(now()));
        $this->assertNull($old->client_created_at);
    }

    public function test_client_uuid_must_be_a_uuid(): void
    {
        $this->asUser($this->citizen())->postJson('/api/declarations', $this->declaration(['client_uuid' => 'pas-un-uuid']))
            ->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
    }

    public function test_the_same_offline_publication_sent_twice_is_recorded_once(): void
    {
        $finder = $this->citizen();
        $type = DocumentType::factory()->create();
        $payload = fn () => [
            'image'             => UploadedFile::fake()->image('cni.jpg'),
            'OwnerFirstName'    => 'Awa',
            'OwnerLastName'     => 'Ndiaye',
            'Location'          => 'Marché Sandaga',
            'document_type_id'  => $type->id,
            'client_uuid'       => '6f1c1b56-3c1e-4c58-9d0b-5c3f7a9e2b11',
            'client_created_at' => now()->subHour()->toIso8601String(),
        ];

        $first = $this->asUser($finder)->post('/api/documents', $payload(), ['Accept' => 'application/json'])->assertCreated();
        $this->asUser($finder)->post('/api/documents', $payload(), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('document.uuid', $first->json('document.uuid'));

        $this->assertSame(1, Document::count());
        $this->assertNotNull(Document::sole()->client_created_at);
    }
}
