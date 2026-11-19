<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\RegistrationPin;
use App\Support\Auth\PendingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** PIN terakhir yang dikirim lewat email (Notification::fake). */
    private function sentPin(): string
    {
        $pin = '';
        Notification::assertSentOnDemand(RegistrationPin::class, function (RegistrationPin $notification) use (&$pin) {
            $pin = $notification->pin;

            return true;
        });

        return $pin;
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.register');
    }

    public function test_new_users_can_register_after_verifying_email_pin(): void
    {
        Notification::fake();

        Volt::test('pages.auth.register')
            ->set('name', 'Test User')
            ->set('email', 'Test@Example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertRedirect(route('register.verify'));

        $this->assertGuest();
        $this->assertSame(0, User::count()); // akun belum dibuat sebelum PIN benar

        Notification::assertSentOnDemand(RegistrationPin::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'test@example.com');

        Volt::test('pages.auth.register-verify')
            ->set('pin', $this->sentPin())
            ->call('verify')
            ->assertHasNoErrors()
            ->assertRedirect(route('onboarding'));

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_wrong_pin_is_limited_and_resend_has_cooldown(): void
    {
        Notification::fake();
        PendingRegistration::start('Rina', 'rina@example.com', 'password');

        $page = Volt::test('pages.auth.register-verify');

        foreach (range(1, PendingRegistration::MAX_ATTEMPTS) as $attempt) {
            $page->set('pin', '000000')->call('verify')->assertHasErrors('pin');
        }

        // PIN benar pun ditolak setelah batas percobaan; perlu kode baru.
        $page->set('pin', $this->sentPin())->call('verify')->assertHasErrors('pin');
        $this->assertSame(0, User::count());

        $page->call('resend')->assertHasErrors('pin'); // masih dalam jeda 60 detik

        $this->travel(PendingRegistration::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $page->call('resend')->assertHasNoErrors();
        Notification::assertSentOnDemandTimes(RegistrationPin::class, 2);
    }

    public function test_expired_pin_and_missing_session_are_rejected(): void
    {
        Volt::test('pages.auth.register-verify')->assertRedirect(route('register'));

        Notification::fake();
        PendingRegistration::start('Rina', 'rina@example.com', 'password');
        $this->travel(PendingRegistration::VALID_MINUTES + 1)->minutes();

        Volt::test('pages.auth.register-verify')->set('pin', '123456')->call('verify')->assertHasErrors('pin');
        $this->assertSame(0, User::count());
    }
}
