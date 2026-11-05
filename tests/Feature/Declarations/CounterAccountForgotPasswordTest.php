<?php

namespace Tests\Feature\Declarations;

use App\Models\DocumentType;
use App\Models\User;
use App\Notifications\CitizenAccountActivationNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\OrganizationTestCase;

/**
 * Personne enregistrée au guichet avec son email : si elle n'a pas activé son compte à temps,
 * « mot de passe oublié » lui renvoie le lien d'activation (elle choisit son mot de passe en un clic).
 */
class CounterAccountForgotPasswordTest extends OrganizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        Mail::fake();
    }

    public function test_forgot_password_resends_the_activation_link_of_a_counter_account(): void
    {
        $cni = DocumentType::factory()->create(['TypeName' => "Carte nationale d'identité"]);
        $this->asUser($this->agent($this->commissariatA))->postJson('/api/v2/declarations', [
            'citizen' => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '77 123 45 67', 'email' => 'awa@example.sn'],
            'items'   => [['document_type_id' => $cni->id, 'first_name' => 'Awa', 'last_name' => 'Ndiaye']],
        ])->assertCreated();

        $user = User::where('email', 'awa@example.sn')->firstOrFail();
        $this->assertNull($user->email_verified_at);

        $this->travel(10)->days(); // le premier lien (7 jours) a expiré

        $this->postJson('/api/forgot-password', ['email' => 'AWA@example.sn'])->assertOk();

        $token = null;
        Notification::assertSentToTimes($user, CitizenAccountActivationNotification::class, 2);
        Notification::assertSentTo($user, CitizenAccountActivationNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->postJson('/api/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'MotDePasse-2026', 'password_confirmation' => 'MotDePasse-2026',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'MotDePasse-2026'])->assertOk();
    }

    public function test_the_answer_is_the_same_for_an_unknown_email(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'personne@example.sn'])
            ->assertOk()->assertJsonPath('success', true);
        Notification::assertNothingSent();
    }
}
