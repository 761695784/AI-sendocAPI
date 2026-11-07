<?php

namespace Tests\Feature\Security;

use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Aucune donnée personnelle ni secret ne doit sortir par les routes publiques
 * ou vers un autre citoyen.
 */
class DataExposureTest extends TestCase
{
    private function publishDocument($owner, array $data = []): Document
    {
        $type = DocumentType::factory()->create();

        $response = $this->asUser($owner)->post('/api/documents', array_merge([
            'image'             => UploadedFile::fake()->image('cni.jpg', 600, 400),
            'OwnerFirstName'    => 'Awa',
            'OwnerLastName'     => 'Ndiaye',
            'DocIdentification' => '1234567890123',
            'Location'          => 'Dakar',
            'document_type_id'  => $type->id,
        ], $data), ['Accept' => 'application/json']);

        $response->assertCreated();

        return Document::latest('id')->firstOrFail();
    }

    public function test_public_listing_hides_publisher_contact_and_secrets(): void
    {
        $publisher = $this->citizen();
        $this->publishDocument($publisher);

        $response = $this->getJson('/api/documents')->assertOk();
        $json     = $response->getContent();

        $this->assertStringNotContainsString($publisher->email, $json);
        $this->assertStringNotContainsString($publisher->Phone, $json);
        $this->assertStringNotContainsString($publisher->qr_code_token, $json);
        $this->assertStringNotContainsString('email_verification_code', $json);
        $this->assertStringNotContainsString('qr_code_token', $json);
        $this->assertStringNotContainsString('1234567890123', $json);

        $item = $response->json('data.0');
        $this->assertNull($item['DocIdentification']); // aucun chiffre public (preuve de propriété)
        $this->assertSame(['uuid', 'FirstName', 'LastName'], array_keys($item['user']));
        $this->assertStringNotContainsString('signature=', (string) $item['image']);
    }

    public function test_public_detail_never_exposes_the_original_image(): void
    {
        $document = $this->publishDocument($this->citizen());

        $item = $this->getJson('/api/documents/' . $document->uuid)->assertOk()->json();

        $this->assertStringContainsString('blurred', $item['image']);
        $this->assertSame($item['image_blurred'], $item['image_optimized']);

        // L'original est sur le disque privé, jamais sur le disque public.
        $media = $document->getFirstMedia('document_image');
        $this->assertSame('local', $media->disk);
        Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
        Storage::disk('public')->assertMissing($media->getPathRelativeToRoot());
    }

    public function test_publisher_gets_full_number_and_a_working_signed_image_url(): void
    {
        $owner    = $this->citizen();
        $document = $this->publishDocument($owner);

        $item = $this->asUser($owner)->getJson('/api/documents/' . $document->uuid)->assertOk()->json();

        $this->assertSame('1234567890123', $item['DocIdentification']);
        $this->assertTrue($item['is_owner']);
        $this->assertStringContainsString('signature=', $item['image']);

        $this->get($item['image'])->assertOk();
        // Sans signature valide : refusé.
        $this->get(strtok($item['image'], '?'))->assertForbidden();
    }

    public function test_other_citizen_sees_the_public_view_only(): void
    {
        $document = $this->publishDocument($this->citizen());

        $item = $this->asUser($this->citizen())->getJson('/api/documents/' . $document->uuid)->json();

        $this->assertFalse($item['is_owner']);
        $this->assertNull($item['DocIdentification']); // aucun chiffre public (preuve de propriété)
        $this->assertArrayNotHasKey('email', $item['user']);
    }

    public function test_publication_now_stores_the_document_number(): void
    {
        $document = $this->publishDocument($this->citizen());

        $this->assertSame('1234567890123', $document->DocIdentification);
    }

    public function test_pdf_upload_is_rejected_cleanly(): void
    {
        $type = DocumentType::factory()->create();

        $this->asUser($this->citizen())->postJson('/api/documents', [
            'image'            => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
            'OwnerFirstName'   => 'Awa',
            'OwnerLastName'    => 'Ndiaye',
            'Location'         => 'Dakar',
            'document_type_id' => $type->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_updating_a_document_with_an_image_no_longer_crashes(): void
    {
        $owner    = $this->citizen();
        $document = $this->publishDocument($owner);

        $this->asUser($owner)->put('/api/documents/' . $document->uuid, [
            'statut' => 'récupéré',
            'image'  => UploadedFile::fake()->image('new.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('récupéré', $document->fresh()->statut);
    }

    public function test_public_comments_hide_author_contact(): void
    {
        $author   = $this->citizen();
        $document = $this->publishDocument($this->citizen());

        $this->asUser($author)->postJson('/api/comments', [
            'contenu'     => 'Je pense que c\'est la mienne',
            'document_id' => $document->id,
        ])->assertCreated();

        $json = $this->getJson('/api/documents/' . $document->uuid . '/comments')->assertOk()->getContent();

        $this->assertStringNotContainsString($author->email, $json);
        $this->assertStringNotContainsString($author->Phone, $json);
    }

    public function test_removed_public_write_route_is_gone(): void
    {
        $this->postJson('/api/document', [])->assertStatus(404);
    }
}
