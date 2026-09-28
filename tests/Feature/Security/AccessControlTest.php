<?php

namespace Tests\Feature\Security;

use App\Models\Commentaire;
use App\Models\DeclarationDePerte;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\EmailLog;
use App\Models\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    public static function adminOnlyRoutes(): array
    {
        return [
            ['get', '/api/users'],
            ['get', '/api/activity-logs'],
            ['get', '/api/admin/certificats'],
            ['get', '/api/all-publications'],
            ['get', '/api/publications-by-type'],
            ['get', '/api/restitution-data'],
            ['get', '/api/email-activity'],
            ['get', '/api/statistics'],
            ['get', '/api/status-count'],
            ['get', '/api/deleted-documents'],
            ['get', '/api/recovered-documents'],
            ['get', '/api/not-recovered-documents'],
            ['get', '/api/backup/list'],
            ['get', '/api/rapports'],
            ['post', '/api/create-admin'],
            ['post', '/api/document-types'],
        ];
    }

    /** @dataProvider adminOnlyRoutes */
    public function test_citizen_is_refused_on_admin_routes(string $method, string $uri): void
    {
        $this->asUser($this->citizen())->json($method, $uri)->assertForbidden();
    }

    /** @dataProvider adminOnlyRoutes */
    public function test_anonymous_is_refused_on_admin_routes(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    public function test_admin_can_reach_admin_routes(): void
    {
        $admin = $this->admin();

        $this->asUser($admin)->getJson('/api/users')->assertOk();
        $this->asUser($admin)->getJson('/api/all-publications')->assertOk();
        $this->asUser($admin)->getJson('/api/statistics')->assertOk();
    }

    public function test_citizen_cannot_create_or_delete_document_types(): void
    {
        $type = DocumentType::factory()->create();

        $this->asUser($this->citizen())->postJson('/api/document-types', [
            'TypeName' => 'Faux', 'IdentificationSize' => '1',
        ])->assertForbidden();

        $this->asUser($this->citizen())->deleteJson('/api/document-types/' . $type->id)->assertForbidden();
        $this->assertModelExists($type);
    }

    public function test_citizen_can_still_read_document_types(): void
    {
        DocumentType::factory()->count(2)->create();

        $this->asUser($this->citizen())->getJson('/api/document-types')->assertOk()->assertJsonCount(2);
    }

    public function test_admin_cannot_delete_a_type_in_use(): void
    {
        $document = Document::factory()->create();

        $this->asUser($this->admin())
            ->deleteJson('/api/document-types/' . $document->document_type_id)
            ->assertStatus(409);

        $this->assertModelExists($document);
    }

    public function test_admin_can_create_update_and_delete_an_unused_type(): void
    {
        $admin = $this->admin();

        $id = $this->asUser($admin)->postJson('/api/document-types', [
            'TypeName' => 'Carte consulaire', 'IdentificationSize' => '10 chiffres',
        ])->assertOk()->json('documentType.id');

        $this->asUser($admin)->putJson('/api/document-types/' . $id, ['IdentificationSize' => '12 chiffres'])->assertOk();
        $this->asUser($admin)->deleteJson('/api/document-types/' . $id)->assertOk();
    }

    public function test_citizen_only_sees_his_own_notifications_and_emails(): void
    {
        $alice = $this->citizen();
        $bob   = $this->citizen();

        $aliceDecl = DeclarationDePerte::factory()->for($alice)->create();
        $bobDecl   = DeclarationDePerte::factory()->for($bob)->create();
        Notification::factory()->for($aliceDecl, 'declaration')->create(['message' => 'pour alice']);
        Notification::factory()->for($bobDecl, 'declaration')->create(['message' => 'pour bob']);

        EmailLog::create(['from' => 'x', 'to' => $alice->email, 'subject' => 'Correspondance à votre déclaration de perte', 'body' => 'tel 770000001', 'declarant_user_id' => $alice->id]);
        EmailLog::create(['from' => 'x', 'to' => $bob->email, 'subject' => 'Correspondance à votre déclaration de perte', 'body' => 'tel 770000002', 'declarant_user_id' => $bob->id]);

        $notifs = $this->asUser($alice)->getJson('/api/notifications')->assertOk()->json('data');
        $this->assertCount(1, $notifs);
        $this->assertSame('pour alice', $notifs[0]['message']);

        $emails = $this->asUser($alice)->getJson('/api/all-emails')->assertOk()->json('data');
        $this->assertCount(1, $emails);
        $this->assertNull($emails[0]['body']);

        $all = $this->asUser($alice)->getJson('/api/all-notifications')->assertOk();
        $this->assertCount(1, $all->json('declarations'));
        $this->assertStringNotContainsString($bob->email, $all->getContent());

        // L'Admin voit tout.
        $this->assertCount(2, $this->asUser($this->admin())->getJson('/api/notifications')->json('data'));
    }

    public function test_citizen_cannot_mark_someone_elses_notification(): void
    {
        $bobNotif = Notification::factory()->create();

        $this->asUser($this->citizen())
            ->patchJson('/api/notifications/' . $bobNotif->uuid . '/mark-as-read')
            ->assertNotFound();

        $this->assertModelExists($bobNotif);
    }

    public function test_citizen_comments_listing_is_limited_to_his_own(): void
    {
        $alice = $this->citizen();
        Commentaire::factory()->for($alice)->create();
        Commentaire::factory()->create();

        $this->asUser($alice)->getJson('/api/comments')->assertOk()->assertJsonCount(1);
    }

    public function test_only_the_author_can_delete_a_comment(): void
    {
        $comment = Commentaire::factory()->create();

        $this->asUser($this->citizen())->deleteJson('/api/comments/' . $comment->uuid)->assertForbidden();
        $this->asUser($comment->user)->deleteJson('/api/comments/' . $comment->uuid)->assertOk();
    }

    public function test_declaration_receipt_is_private_and_owner_only(): void
    {
        $owner = $this->citizen();
        $type  = DocumentType::factory()->create();

        $response = $this->asUser($owner)->postJson('/api/declarations', [
            'Title'            => 'Perte CNI',
            'FirstNameInDoc'   => 'Awa',
            'LastNameInDoc'    => 'Ndiaye',
            'document_type_id' => $type->id,
        ])->assertCreated();

        $certificat = $response->json('data.certificat');

        Storage::disk('local')->assertExists($certificat['pdf_path']);
        Storage::disk('public')->assertMissing($certificat['pdf_path']);

        $this->asUser($owner)->get('/api/certificats/' . $certificat['uuid'] . '/voir')->assertOk();
        $this->asUser($this->citizen())->getJson('/api/certificats/' . $certificat['uuid'] . '/voir')->assertForbidden();
        $this->asUser($this->admin())->get('/api/certificats/' . $certificat['uuid'] . '/voir')->assertOk();
    }

    public function test_declaration_rejects_unknown_document_type(): void
    {
        $this->asUser($this->citizen())->postJson('/api/declarations', [
            'Title' => 'x', 'FirstNameInDoc' => 'a', 'LastNameInDoc' => 'b', 'document_type_id' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors('document_type_id');
    }
}
