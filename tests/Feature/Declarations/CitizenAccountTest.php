<?php

namespace Tests\Feature\Declarations;

use App\Models\CitizenProfile;
use App\Models\DeclarationDePerte;
use App\Models\DocumentType;
use App\Models\User;
use App\Notifications\CitizenAccountActivationNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;

/** Victime reçue au guichet : son compte DocTrack et le rattachement de ses déclarations. */
class CitizenAccountTest extends OrganizationTestCase
{
    private DocumentType $cni;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        Mail::fake();
        $this->cni = DocumentType::factory()->create(['TypeName' => "Carte nationale d'identité"]);
    }

    private function declare(array $citizen)
    {
        return $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/declarations', [
            'citizen' => $citizen + ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 123 45 67'],
            'items'   => [['document_type_id' => $this->cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye', 'doc_number' => '1234567890123']],
        ]);
    }

    public function test_with_an_email_the_account_is_created_and_activated_by_the_citizen(): void
    {
        $r = $this->declare(['email' => 'Awa.Ndiaye@example.sn'])->assertCreated();
        $r->assertJsonPath('citizen.account_status', 'pending');

        $user = User::where('email', 'awa.ndiaye@example.sn')->firstOrFail();
        $this->assertTrue($user->isCitizen());
        $this->assertTrue($user->hasRole(User::ROLE_CITIZEN));
        $this->assertNull($user->email_verified_at);
        $declaration = DeclarationDePerte::where('reference', $r->json('reference'))->first();
        $this->assertSame($user->id, $declaration->user_id);

        // Lien d'activation : la personne choisit son mot de passe (même écran que « mot de passe oublié »).
        $token = null;
        Notification::assertSentTo($user, CitizenAccountActivationNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->travel(3)->days();   // le lien reste valable plusieurs jours
        $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'MotDePasse-2026', 'password_confirmation' => 'MotDePasse-2026'])
            ->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);

        $login = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'MotDePasse-2026'])->assertOk();
        $this->withHeader('Authorization', 'Bearer ' . $login->json('token'))
            ->getJson('/api/my-declarations')->assertOk()
            ->assertJsonPath('data.0.reference', $declaration->reference);
    }

    public function test_without_email_the_declarations_are_attached_when_the_person_registers_online(): void
    {
        $r = $this->declare([])->assertCreated();
        $r->assertJsonPath('citizen.account_status', 'none');
        $declaration = DeclarationDePerte::where('reference', $r->json('reference'))->first();
        $this->assertNull($declaration->user_id);

        $this->postJson('/api/register', [
            'FirstName' => 'Awa', 'LastName' => 'NDIAYE', 'Adress' => 'Dakar', 'Phone' => '+221771234567',
            'email' => 'awa@example.sn', 'password' => 'MotDePasse-2026', 'password_confirmation' => 'MotDePasse-2026',
        ])->assertSuccessful();
        $user = User::where('email', 'awa@example.sn')->firstOrFail();
        $this->assertNull($declaration->fresh()->user_id); // pas avant la vérification de l'email

        $this->postJson('/api/verify-email', ['email' => $user->email, 'code' => $user->email_verification_code])->assertOk();
        $this->assertSame($user->id, $declaration->fresh()->user_id);
        $this->assertSame($user->id, CitizenProfile::first()->user_id);
    }

    public function test_an_email_belonging_to_someone_else_is_refused_and_nothing_is_saved(): void
    {
        User::factory()->create(['email' => 'pris@example.sn', 'LastName' => 'Diop', 'account_type' => User::TYPE_CITIZEN]);

        $this->declare(['email' => 'pris@example.sn'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(0, CitizenProfile::count());
        $this->assertSame(0, DeclarationDePerte::count());
    }

    public function test_agent_creates_the_account_of_a_known_person_then_resends_the_link(): void
    {
        $this->declare([])->assertCreated();
        $profile = CitizenProfile::firstOrFail();
        $agent = $this->agent($this->commissariatA);

        $this->asUser($agent)->postJson("/api/v2/citizens/{$profile->uuid}/account", ['email' => 'awa@example.sn'])
            ->assertOk()->assertJsonPath('account_status', 'pending');
        $user = User::where('email', 'awa@example.sn')->firstOrFail();
        $this->assertSame($user->id, DeclarationDePerte::first()->user_id);

        $this->asUser($agent)->postJson("/api/v2/citizens/{$profile->uuid}/account")->assertOk();
        Notification::assertSentToTimes($user, CitizenAccountActivationNotification::class, 2);
    }
}
