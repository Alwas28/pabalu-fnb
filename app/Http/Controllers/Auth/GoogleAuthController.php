<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Daftar & masuk dengan akun Google.
 *
 * - Email Google sudah terdaftar → langsung masuk (akun ditautkan ke Google bila belum).
 * - Email baru → nama & email dari Google disimpan sementara di sesi, lalu pengguna membuat
 *   password di halaman "Lengkapi pendaftaran" sebelum menyiapkan usahanya (onboarding).
 */
class GoogleAuthController extends Controller
{
    /** Kunci sesi data Google yang menunggu pendaftaran diselesaikan. */
    public const SIGNUP_SESSION = 'google_signup';

    /** Kunci sesi untuk popup "Berhasil terhubung dengan Gmail" (diambil sekali oleh x-google-connected-popup). */
    public const CONNECTED_SESSION = 'google_connected';

    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable) {
            return $this->fail('Gagal terhubung dengan Google. Silakan coba lagi.');
        }

        $email = Str::lower(trim((string) $google->getEmail()));

        $raw = $google instanceof AbstractUser ? $google->getRaw() : [];

        if ($email === '' || ($raw['email_verified'] ?? true) === false) {
            return $this->fail('Email akun Google ini belum terverifikasi.');
        }

        $user = User::where('google_id', $google->getId())->first() ?? User::where('email', $email)->first();

        if ($user === null) {
            session()->put(self::SIGNUP_SESSION, [
                'google_id' => (string) $google->getId(),
                'name' => (string) ($google->getName() ?: Str::before($email, '@')),
                'email' => $email,
                'expires_at' => now()->addMinutes(30)->timestamp,
            ]);
            session()->put(self::CONNECTED_SESSION, ['mode' => 'register', 'email' => $email]);

            return redirect()->route('register.google');
        }

        if ($user->isDeactivated()) {
            return $this->fail(EnsureAccountIsActive::MESSAGE);
        }

        if ($user->google_id !== null && $user->google_id !== (string) $google->getId()) {
            return $this->fail('Email ini sudah tertaut ke akun Google lain.');
        }

        $user->forceFill([
            'google_id' => (string) $google->getId(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user, remember: true);
        session()->regenerate();
        session()->put(self::CONNECTED_SESSION, ['mode' => 'login', 'email' => $user->email, 'name' => $user->name]);

        $home = $user->isSuperAdmin() ? route('admin.dashboard', absolute: false) : route('dashboard', absolute: false);

        return redirect()->intended($home);
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['google' => $message]);
    }
}
