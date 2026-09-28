<?php

namespace Tests\Feature;

use App\Mail\DocumentPublishedNotification;
use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\EmailLog;
use App\Models\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MatchingNotificationTest extends TestCase
{
    public function test_publishing_a_matching_document_notifies_the_declarant(): void
    {
        Mail::fake();
        $type        = DocumentType::factory()->create();
        $declarant   = $this->citizen();
        $declaration = DeclarationDePerte::factory()->for($declarant)->create([
            'FirstNameInDoc'   => 'Awa',
            'LastNameInDoc'    => 'Ndiaye',
            'document_type_id' => $type->id,
        ]);

        $this->asUser($this->citizen())->post('/api/documents', [
            'image'            => UploadedFile::fake()->image('cni.jpg'),
            'OwnerFirstName'   => 'AWA',
            'OwnerLastName'    => 'ndiaye',
            'Location'         => 'Dakar',
            'document_type_id' => $type->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        Mail::assertSent(DocumentPublishedNotification::class, fn ($m) => $m->hasTo($declarant->email));
        // Avant le correctif, cette notification n'était jamais enregistrée.
        $this->assertDatabaseHas('notifications', ['declaration_de_perte_id' => $declaration->id]);
        $this->assertSame(1, EmailLog::where('declarant_user_id', $declarant->id)->count());
    }

    public function test_new_declaration_is_matched_against_existing_documents_but_not_returned_ones(): void
    {
        Mail::fake();
        $type = DocumentType::factory()->create();
        Document::factory()->create(['OwnerFirstName' => 'Awa', 'OwnerLastName' => 'Ndiaye', 'document_type_id' => $type->id]);
        Document::factory()->create(['OwnerFirstName' => 'Awa', 'OwnerLastName' => 'Ndiaye', 'document_type_id' => $type->id, 'statut' => 'récupéré']);

        $declarant = $this->citizen();

        $this->asUser($declarant)->postJson('/api/declarations', [
            'Title'            => 'Perte',
            'FirstNameInDoc'   => 'awa',
            'LastNameInDoc'    => 'NDIAYE',
            'document_type_id' => $type->id,
        ])->assertCreated();

        Mail::assertSent(DocumentPublishedNotification::class, 1);
        $this->assertSame(1, Notification::count());
    }

    public function test_publisher_is_not_notified_about_his_own_declaration(): void
    {
        Mail::fake();
        $type = DocumentType::factory()->create();
        $user = $this->citizen();
        DeclarationDePerte::factory()->for($user)->create([
            'FirstNameInDoc' => 'Awa', 'LastNameInDoc' => 'Ndiaye', 'document_type_id' => $type->id,
        ]);

        $this->asUser($user)->post('/api/documents', [
            'image'            => UploadedFile::fake()->image('cni.jpg'),
            'OwnerFirstName'   => 'Awa',
            'OwnerLastName'    => 'Ndiaye',
            'Location'         => 'Dakar',
            'document_type_id' => $type->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        Mail::assertNothingSent();
    }
}
