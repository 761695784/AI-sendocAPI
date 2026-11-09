<?php

namespace Tests\Feature\Testimonials;

use App\Jobs\SendSmsJob;
use App\Models\DeclarationDePerte;
use App\Models\DocumentType;
use App\Models\InboxMessage;
use App\Models\NotificationDelivery;
use App\Models\Testimonial;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Témoignages : dépôt public, masquage des numéros, modération par le super admin,
 * badge « restitution vérifiée » et invitation automatique après une restitution.
 */
class TestimonialTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Bus::fake([SendSmsJob::class]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'first_name' => 'Awa',
            'city'       => 'Thiès',
            'role'       => 'owner',
            'rating'     => 5,
            'body'       => 'J\'ai retrouvé ma carte en deux jours grâce à DocTrack, merci à toute l\'équipe !',
            'consent'    => true,
            'contact'    => '77 123 45 67',
            'locale'     => 'fr',
        ], $extra);
    }

    public function test_a_visitor_can_submit_a_testimonial_which_waits_for_validation(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/v2/testimonials', $this->payload())->assertCreated()->assertJsonPath('status', 'pending');

        $t = Testimonial::sole();
        $this->assertSame('pending', $t->status);
        $this->assertSame('77 123 45 67', $t->contact_encrypted);
        $this->assertFalse($t->verified);
        $this->assertNotNull($t->consent_at);

        // Rien n'est public avant validation.
        $this->getJson('/api/v2/testimonials')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('stats.count', 0);

        // Le super admin est prévenu (boîte de réception + e-mail).
        $this->assertSame(1, InboxMessage::where('event', 'testimonial.pending')->where('user_id', $admin->id)->count());
        $this->assertSame(1, NotificationDelivery::where('event', 'testimonial.submitted')->where('channel', 'email')->count());
    }

    public function test_consent_is_required_and_robots_are_refused(): void
    {
        $this->postJson('/api/v2/testimonials', $this->payload(['consent' => false]))->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->postJson('/api/v2/testimonials', $this->payload(['website' => 'http://spam.example']))->assertUnprocessable();
        $this->postJson('/api/v2/testimonials', $this->payload(['body' => 'Trop court']))->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertSame(0, Testimonial::count());
    }

    public function test_numbers_and_emails_written_in_the_text_are_masked(): void
    {
        $this->postJson('/api/v2/testimonials', $this->payload([
            'body' => 'Ma carte 1 234 567 890 123 a été rendue, appelez-moi au 77 123 45 67 ou awa@example.com. Merci !',
        ]))->assertCreated();

        $body = Testimonial::sole()->body;
        $this->assertStringNotContainsString('567', $body);
        $this->assertStringNotContainsString('45 67', $body);
        $this->assertStringNotContainsString('@', $body);
        $this->assertStringContainsString('Merci', $body);
    }

    public function test_super_admin_approves_and_the_testimonial_becomes_public_without_contact(): void
    {
        $this->postJson('/api/v2/testimonials', $this->payload())->assertCreated();
        $t = Testimonial::sole();
        $admin = $this->admin();

        // Un citoyen ne peut pas modérer.
        $this->asUser($this->citizen())->patchJson("/api/v2/testimonials/{$t->uuid}", ['status' => 'approved'])->assertForbidden();

        $this->asUser($admin)->getJson('/api/v2/testimonials/moderation')->assertOk()
            ->assertJsonPath('data.0.contact', '77 123 45 67')->assertJsonPath('counts.pending', 1);

        $this->asUser($admin)->patchJson("/api/v2/testimonials/{$t->uuid}", ['status' => 'approved', 'city' => 'Thiès Nord'])
            ->assertOk()->assertJsonPath('status', 'approved');

        $public = $this->getJson('/api/v2/testimonials')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.city', 'Thiès Nord')
            ->assertJsonPath('stats.average_rating', 5.0);
        $this->assertArrayNotHasKey('contact', $public->json('data.0'));
        $this->assertStringNotContainsString('77 123 45 67', $public->getContent());

        // Refus : retiré du site.
        $this->asUser($admin)->patchJson("/api/v2/testimonials/{$t->uuid}", ['status' => 'rejected', 'moderation_note' => 'Hors sujet'])->assertOk();
        $this->getJson('/api/v2/testimonials')->assertJsonCount(0, 'data');
    }

    public function test_restitution_invites_the_owner_once_and_his_testimonial_is_verified(): void
    {
        $cni    = DocumentType::factory()->create(['TypeName' => "Carte nationale d'identité"]);
        $owner  = $this->citizen(['FirstName' => 'Awa', 'Phone' => '+221771112233', 'email' => 'awa@example.sn']);
        $finder = $this->citizen(['FirstName' => 'Moussa', 'Phone' => '+221784445566']);

        $this->asUser($owner)->postJson('/api/declarations', [
            'Title' => 'Perte CNI', 'FirstNameInDoc' => 'Awa', 'LastNameInDoc' => 'Ndiaye',
            'DocIdentification' => '1234567890123', 'document_type_id' => $cni->id,
        ])->assertCreated();
        $docUuid = $this->asUser($finder)->post('/api/documents', [
            'image' => UploadedFile::fake()->image('cni.jpg', 600, 400), 'OwnerFirstName' => 'Awa', 'OwnerLastName' => 'Ndiaye',
            'DocIdentification' => '1234 5678 90123', 'Location' => 'Dakar', 'document_type_id' => $cni->id,
        ], ['Accept' => 'application/json'])->assertCreated()->json('document.uuid');

        // Le trouveur confirme la remise : la déclaration passe « restituée ».
        $this->asUser($finder)->post('/api/documents/' . $docUuid, ['_method' => 'PUT', 'statut' => 'récupéré'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(DeclarationDePerte::STATUS_RESTITUTED, DeclarationDePerte::sole()->status);

        // Invitation par SMS et par e-mail, une seule fois.
        $this->assertSame(1, NotificationDelivery::where('event', 'testimonial.invite')->where('channel', 'sms')->count());
        $this->assertSame(1, NotificationDelivery::where('event', 'testimonial.invite')->where('channel', 'email')->count());
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => str_contains(json_encode((array) $job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '/temoigner'));

        // Témoignage du propriétaire connecté : badge « restitution vérifiée ».
        $this->asUser($owner)->postJson('/api/v2/testimonials', $this->payload())->assertCreated();
        $t = Testimonial::sole();
        $this->assertTrue($t->verified);
        $this->assertSame(DeclarationDePerte::sole()->id, $t->declaration_de_perte_id);

        // Le trouveur aussi (il a rendu un document).
        $this->asUser($finder)->postJson('/api/v2/testimonials', $this->payload(['first_name' => 'Moussa', 'role' => 'finder']))->assertCreated();
        $this->assertTrue(Testimonial::where('first_name', 'Moussa')->sole()->verified);
    }

    public function test_invitation_can_be_switched_off(): void
    {
        config(['doctrack.testimonials.invite_after_restitution' => false]);
        $declaration = DeclarationDePerte::factory()->create();
        app(\App\Services\Testimonials\TestimonialService::class)->inviteAfterRestitution($declaration);

        $this->assertSame(0, NotificationDelivery::where('event', 'testimonial.invite')->count());
    }
}
