<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Notifications\RegistrationPin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Pendaftaran dengan email & password yang menunggu verifikasi PIN.
 *
 * Akun BELUM dibuat sampai PIN dari email dimasukkan dengan benar, sehingga email orang lain
 * tidak bisa didaftarkan tanpa akses ke inbox-nya dan akun lama tidak terpengaruh.
 * Data disimpan di sesi: password sudah di-hash, PIN disimpan sebagai HMAC (bukan teks asli).
 */
class PendingRegistration
{
    public const SESSION = 'pending_registration';

    public const VALID_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public const MAX_SENDS = 5;

    /** Simpan data pendaftaran & kirim PIN pertama. */
    public static function start(string $name, string $email, string $password): void
    {
        session()->put(self::SESSION, [
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'sends' => 0,
        ]);

        self::sendPin();
    }

    /** @return array{name: string, email: string, password: string, pin_hash: string, expires_at: int, attempts: int, sent_at: int, sends: int}|null */
    public static function current(): ?array
    {
        $pending = session(self::SESSION);

        return is_array($pending) && isset($pending['email']) ? $pending : null;
    }

    /** Detik tersisa sebelum PIN boleh dikirim ulang. */
    public static function resendWaitSeconds(): int
    {
        $pending = self::current();

        return $pending ? max(0, ($pending['sent_at'] ?? 0) + self::RESEND_COOLDOWN_SECONDS - now()->timestamp) : 0;
    }

    /**
     * @throws ValidationException
     */
    public static function resend(): void
    {
        $pending = self::current();

        if ($pending === null) {
            return;
        }

        if (self::resendWaitSeconds() > 0) {
            throw ValidationException::withMessages(['pin' => 'Tunggu '.self::resendWaitSeconds().' detik sebelum meminta kode baru.']);
        }

        if ($pending['sends'] >= self::MAX_SENDS) {
            throw ValidationException::withMessages(['pin' => 'Terlalu banyak permintaan kode. Silakan daftar ulang beberapa saat lagi.']);
        }

        self::sendPin();
    }

    /**
     * Cocokkan PIN lalu buat akun (email langsung terverifikasi).
     *
     * @throws ValidationException
     */
    public static function verify(string $pin): User
    {
        $pending = self::current();

        if ($pending === null) {
            throw ValidationException::withMessages(['pin' => 'Sesi pendaftaran berakhir. Silakan daftar ulang.']);
        }

        if (now()->timestamp > $pending['expires_at']) {
            throw ValidationException::withMessages(['pin' => 'Kode sudah kedaluwarsa. Minta kode baru.']);
        }

        if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['pin' => 'Terlalu banyak percobaan salah. Minta kode baru.']);
        }

        if (! hash_equals($pending['pin_hash'], self::hash($pending['email'], trim($pin)))) {
            $pending['attempts']++;
            session()->put(self::SESSION, $pending);
            $left = self::MAX_ATTEMPTS - $pending['attempts'];

            throw ValidationException::withMessages(['pin' => $left > 0 ? "Kode salah. Sisa {$left} percobaan." : 'Kode salah. Minta kode baru.']);
        }

        if (User::where('email', $pending['email'])->exists()) {
            self::forget();

            throw ValidationException::withMessages(['pin' => 'Email ini sudah terdaftar. Silakan masuk.']);
        }

        $user = new User(['name' => $pending['name'], 'email' => $pending['email'], 'password' => $pending['password']]);
        $user->forceFill(['email_verified_at' => now()])->save();

        self::forget();

        return $user;
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION);
    }

    private static function sendPin(): void
    {
        $pending = session(self::SESSION);
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $pending['pin_hash'] = self::hash($pending['email'], $pin);
        $pending['expires_at'] = now()->addMinutes(self::VALID_MINUTES)->timestamp;
        $pending['attempts'] = 0;
        $pending['sent_at'] = now()->timestamp;
        $pending['sends'] = ($pending['sends'] ?? 0) + 1;
        session()->put(self::SESSION, $pending);

        Notification::route('mail', $pending['email'])->notify(new RegistrationPin($pin, self::VALID_MINUTES));
    }

    private static function hash(string $email, string $pin): string
    {
        return hash_hmac('sha256', $email.'|'.$pin, (string) config('app.key'));
    }
}
