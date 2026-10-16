<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MailTestCommandTest extends TestCase
{
    public function test_mail_test_command_sends_a_sample_of_every_email(): void
    {
        Notification::fake();
        Mail::fake();

        $this->artisan('doctrack:mail-test', ['email' => 'test@example.sn', '--all' => true])
            ->expectsOutputToContain('9 / 9 exemples envoyés')
            ->assertExitCode(0);

        Mail::assertSent(\App\Mail\DocumentPublishedNotification::class);
    }

    public function test_public_list_accepts_a_larger_page(): void
    {
        $this->getJson('/api/documents?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/documents?per_page=5000')->assertOk()->assertJsonPath('meta.per_page', 100);
    }
}
