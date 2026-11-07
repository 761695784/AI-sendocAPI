<?php

namespace Tests\Feature\Security;

use App\Mail\FoundDocumentNotification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'FirstName'             => 'Awa',
            'LastName'              => 'Ndiaye',
            'Adress'                => 'Dakar',
            'Phone'                 => '+221771234567',
            'email'                 => 'awa@example.com',
            'password'              => 'motdepasse123',
            'password_confirmation' => 'motdepasse123',
        ], $overrides);
    }

    public function test_registration_does_not_leak_the_verification_code_or_qr_token(): void
    {
        $json = $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        $user = User::where('email', 'awa@example.com')->first();

        $this->assertStringNotContainsString('email_verification_code', $json->getContent());
        $this->assertStringNotContainsString($user->email_verification_code, $json->getContent());
        $this->assertStringNotContainsString($user->qr_code_token, $json->getContent());
        // Plus de jeton ni de lien QR avant la vérification de l'email.
        $this->assertNull($json->json('token'));
        $this->assertNull($json->json('qr_code_url'));
        $this->assertTrue($user->hasRole('citizen'));
    }

    public function test_qr_code_image_is_served_only_through_a_signed_url(): void
    {
        // Le lien du QR code est donné au citoyen connecté (après vérification de son email).
        $url = $this->asUser($this->citizen())->getJson('/api/me')->assertOk()->json('qr_code_url');
        $this->assertStringContainsString('signature=', (string) $url);

        $response = $this->get($url)->assertOk();
        $this->assertContains($response->headers->get('Content-Type'), ['image/png', 'image/svg+xml']);
        $this->get(strtok($url, '?'))->assertForbidden();
    }

    public function test_email_verification_flow(): void
    {
        $this->postJson('/api/register', $this->registerPayload());
        $user = User::where('email', 'awa@example.com')->first();

        $this->postJson('/api/verify-email', ['email' => $user->email, 'code' => '000000'])->assertUnauthorized();
        $this->postJson('/api/verify-email', ['email' => $user->email, 'code' => $user->email_verification_code])
            ->assertOk()
            ->assertJsonMissingPath('user.email_verification_code');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_login_gives_the_same_answer_for_unknown_email_and_wrong_password(): void
    {
        $this->citizen(['email' => 'known@example.com']);

        $unknown = $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertUnauthorized();
        $wrong   = $this->postJson('/api/login', ['email' => 'known@example.com', 'password' => 'x'])->assertUnauthorized();

        $this->assertSame($unknown->json('message'), $wrong->json('message'));
    }

    public function test_unverified_email_is_only_revealed_after_a_correct_password(): void
    {
        User::factory()->unverified()->create(['email' => 'new@example.com'])->assignRole('citizen');

        $this->postJson('/api/login', ['email' => 'new@example.com', 'password' => 'wrong'])->assertUnauthorized();
        $this->postJson('/api/login', ['email' => 'new@example.com', 'password' => 'password'])->assertForbidden();
    }

    public function test_login_success_hides_secrets(): void
    {
        $user = $this->citizen(['email' => 'ok@example.com']);

        $response = $this->postJson('/api/login', ['email' => 'ok@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user', 'roles']);

        $this->assertStringNotContainsString($user->qr_code_token, $response->getContent());
    }

    public function test_login_is_locked_after_five_failures(): void
    {
        $this->citizen(['email' => 'target@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'target@example.com', 'password' => 'bad']);
        }

        $this->postJson('/api/login', ['email' => 'target@example.com', 'password' => 'password'])->assertStatus(429);
    }

    public function test_forgot_password_does_not_reveal_whether_an_account_exists(): void
    {
        NotificationFacade::fake();
        $this->citizen(['email' => 'exists@example.com']);

        $a = $this->postJson('/api/forgot-password', ['email' => 'exists@example.com'])->assertOk();
        $b = $this->postJson('/api/forgot-password', ['email' => 'ghost@example.com'])->assertOk();

        $this->assertSame($a->json('message'), $b->json('message'));
    }

    public function test_google_login_is_disabled(): void
    {
        // Le jeton Google n'était pas vérifié comme émis pour DocTrack : routes retirées.
        $this->postJson('/api/auth/google/callback', ['token' => 'faux'])->assertNotFound();
        $this->postJson('/api/auth/google/finalize-account-creation', ['token' => 'faux'])->assertNotFound();
    }

    public function test_forgot_password_is_post_only(): void
    {
        $this->getJson('/api/forgot-password?email=victime@example.com')->assertStatus(405);
    }

    public function test_register_gives_no_token_before_the_email_is_verified(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $res = $this->postJson('/api/register', [
            'FirstName' => 'Awa', 'LastName' => 'Ndiaye', 'Adress' => 'Dakar', 'Phone' => '+221770001122',
            'email' => 'awa.test@example.sn', 'password' => 'motdepasse123', 'password_confirmation' => 'motdepasse123',
        ])->assertCreated()->assertJsonPath('requires_verification', true);
        $this->assertArrayNotHasKey('token', $res->json());

        // Un jeton obtenu autrement ne sert à rien tant que l'email n'est pas vérifié.
        $user = \App\Models\User::where('email', 'awa.test@example.sn')->sole();
        $this->asUser($user)->getJson('/api/my-declarations')->assertForbidden();
    }

    public function test_email_code_is_cancelled_after_five_wrong_attempts(): void
    {
        $user = \App\Models\User::factory()->unverified()->create(['email' => 'code@example.sn', 'email_verification_code' => '123456']);
        $user->assignRole(\App\Models\User::ROLE_CITIZEN);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/verify-email', ['email' => 'code@example.sn', 'code' => '000000'])->assertStatus(401);
        }
        // Même le bon code ne passe plus : il faut demander un lien d'activation.
        $this->postJson('/api/verify-email', ['email' => 'code@example.sn', 'code' => '123456'])->assertStatus(401);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_changing_email_requires_the_current_password(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $user = $this->citizen(['email' => 'avant@example.sn', 'password' => \Illuminate\Support\Facades\Hash::make('secret-123')]);

        $this->asUser($user)->putJson('/api/profil', ['email' => 'pirate@example.sn'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->assertSame('avant@example.sn', $user->fresh()->email);

        $this->asUser($user)->putJson('/api/profil', ['email' => 'nouveau@example.sn', 'current_password' => 'secret-123'])->assertOk();
        $this->assertSame('nouveau@example.sn', $user->fresh()->email);
    }

    public function test_renewing_the_qr_code_changes_the_token(): void
    {
        $user = $this->citizen();
        $old  = $user->qr_code_token;

        $this->asUser($user)->postJson('/api/renew-qr-code')->assertOk()->assertJsonStructure(['qr_code_url', 'new_expiration']);

        $this->assertNotSame($old, $user->fresh()->qr_code_token);
    }

    public function test_found_qr_notifies_owner_without_crashing(): void
    {
        Mail::fake();
        $owner = $this->citizen();

        $this->postJson('/api/found-qr', ['token' => $owner->qr_code_token, 'finder_phone' => '77 123 45 67'])
            ->assertOk()
            ->assertJson(['email_sent' => true, 'sms_sent' => false]); // pas d'identifiants Orange en test

        Mail::assertSent(FoundDocumentNotification::class, fn ($mail) => $mail->finderPhone === '+221771234567');
    }

    public function test_found_qr_rejects_invalid_phone_and_expired_token(): void
    {
        $owner = $this->citizen();

        $this->postJson('/api/found-qr', ['token' => $owner->qr_code_token, 'finder_phone' => '12'])->assertUnprocessable();

        $owner->forceFill(['qr_code_expires_at' => now()->subDay()])->save();
        $this->postJson('/api/found-qr', ['token' => $owner->qr_code_token, 'finder_phone' => '771234567'])->assertNotFound();
    }
}
