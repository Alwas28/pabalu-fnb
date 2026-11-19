<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Livewire\Volt\Volt;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test-client-id', 'services.google.client_secret' => 'test-secret']);
    }

    /** @param  array<string, mixed>  $raw */
    private function fakeGoogle(string $email, string $id = 'google-123', array $raw = ['email_verified' => true]): void
    {
        Socialite::fake('google', GoogleUser::fake(['id' => $id, 'name' => 'Rina Pemilik', 'email' => $email])->setRaw($raw));
    }

    public function test_new_owner_registers_with_google_then_creates_password(): void
    {
        $this->get(route('register'))->assertOk()->assertSee('Daftar dengan Google');

        $this->fakeGoogle('Rina@Gmail.com');
        $this->get(route('auth.google.callback'))->assertRedirect(route('register.google'));
        $this->assertGuest();
        $this->assertSame(0, User::count()); // akun baru dibuat setelah password dibuat

        Volt::test('pages.auth.google-register')
            ->assertSet('name', 'Rina Pemilik')
            ->assertSet('email', 'rina@gmail.com')
            ->set('password', 'rahasia-kuat-123')
            ->set('password_confirmation', 'rahasia-kuat-123')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('onboarding'));

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-123', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull(session(GoogleAuthController::SIGNUP_SESSION));
    }

    public function test_existing_account_signs_in_with_google_and_gets_linked(): void
    {
        $user = User::factory()->create(['email' => 'pemilik@gmail.com']);

        $this->fakeGoogle('pemilik@gmail.com');
        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-123', $user->fresh()->google_id);
    }

    public function test_rejects_unverified_email_deactivated_account_and_other_linked_google(): void
    {
        $this->fakeGoogle('baru@gmail.com', raw: ['email_verified' => false]);
        $this->get(route('auth.google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('google');

        User::factory()->create(['email' => 'nonaktif@gmail.com', 'deactivated_at' => now()]);
        $this->fakeGoogle('nonaktif@gmail.com');
        $this->get(route('auth.google.callback'))->assertSessionHasErrors('google');

        User::factory()->create(['email' => 'tertaut@gmail.com'])->forceFill(['google_id' => 'google-lain'])->save();
        $this->fakeGoogle('tertaut@gmail.com', 'google-999');
        $this->get(route('auth.google.callback'))->assertSessionHasErrors('google');

        $this->assertGuest();
    }

    public function test_completing_registration_requires_fresh_google_session(): void
    {
        Volt::test('pages.auth.google-register')->assertRedirect(route('register'));

        session()->put(GoogleAuthController::SIGNUP_SESSION, [
            'google_id' => 'google-123', 'name' => 'Rina', 'email' => 'rina@gmail.com', 'expires_at' => now()->subMinute()->timestamp,
        ]);
        Volt::test('pages.auth.google-register')->assertRedirect(route('register'));
    }

    public function test_google_button_hidden_when_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->get(route('login'))->assertOk()->assertDontSee('Masuk dengan Google');
    }
}
