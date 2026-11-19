<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Buat akun super admin (pengelola aplikasi) di server, termasuk production.
 *
 *   php artisan pabalu:super-admin admin@pabalu.id --name="Admin Pabalu"
 *
 * Tanpa --password, kata sandi acak dibuat dan ditampilkan sekali.
 * Akun yang sudah ada dijadikan super admin, kecuali akun itu anggota sebuah bisnis.
 */
#[Signature('pabalu:super-admin {email : Email super admin} {--name= : Nama tampilan} {--password= : Kata sandi (minimal 12 karakter)}')]
#[Description('Buat atau tetapkan akun super admin pengelola aplikasi')]
class CreateSuperAdmin extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $password = $this->option('password') ?: Str::password(16, symbols: false);

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'min:12']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);

        if ($user->exists && $user->memberships()->exists()) {
            $this->error('Akun ini anggota sebuah bisnis. Gunakan email lain untuk super admin.');

            return self::FAILURE;
        }

        $user->forceFill([
            'name' => $this->option('name') ?: ($user->name ?? 'Super Admin'),
            'password' => $password,
            'is_super_admin' => true,
            'deactivated_at' => null,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->info("Super admin siap: {$email}");

        if (! $this->option('password')) {
            $this->line("Kata sandi: {$password}");
            $this->warn('Simpan kata sandi ini sekarang; tidak akan ditampilkan lagi.');
        }

        return self::SUCCESS;
    }
}
