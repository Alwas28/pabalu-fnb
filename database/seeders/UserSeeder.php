<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Akun login demo — satu akun untuk setiap role F&B.
 * Semua akun memakai password: "password".
 * Role & penugasan outlet diatur oleh BusinessSeeder.
 *
 * Aman dijalankan berulang (idempotent) dan tidak berjalan di production.
 */
class UserSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'password';

    /** Akun super admin demo (pengelola aplikasi, tidak tergabung di bisnis). */
    public const SUPER_ADMIN = ['name' => 'Super Admin Pabalu', 'email' => 'pabalu.superadmin@gmail.com'];

    /**
     * @return array<int, array{name: string, email: string, role: UserRole, outlets: list<string>}>
     */
    public static function accounts(): array
    {
        // 'outlets' = kode outlet yang ditugaskan (diabaikan untuk Owner & Business Admin: semua outlet).
        return [
            ['name' => 'Ahmad Fulan', 'email' => 'pabalu.owner@gmail.com', 'role' => UserRole::Owner, 'outlets' => []],
            ['name' => 'Rina Admin', 'email' => 'pabalu.admin@gmail.com', 'role' => UserRole::BusinessAdmin, 'outlets' => []],
            ['name' => 'Budi Manager', 'email' => 'pabalu.manager@gmail.com', 'role' => UserRole::ManagerOutlet, 'outlets' => ['KDI']],
            ['name' => 'Sari Supervisor', 'email' => 'pabalu.supervisor@gmail.com', 'role' => UserRole::Supervisor, 'outlets' => ['KDI', 'BAU']],
            ['name' => 'Dedi Kasir', 'email' => 'pabalu.kasir@gmail.com', 'role' => UserRole::Kasir, 'outlets' => ['KDI']],
            ['name' => 'Joko Dapur', 'email' => 'pabalu.dapur@gmail.com', 'role' => UserRole::KitchenStaff, 'outlets' => ['KDI']],
            ['name' => 'Lina Gudang', 'email' => 'pabalu.gudang@gmail.com', 'role' => UserRole::InventoryStaff, 'outlets' => ['KDI', 'BAU']],
        ];
    }

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('UserSeeder dilewati: akun demo tidak boleh dibuat di production.');

            return;
        }

        $password = Hash::make(self::DEFAULT_PASSWORD);

        foreach (self::accounts() as $account) {
            $user = User::firstOrNew(['email' => $account['email']]);

            $user->forceFill([
                'name' => $account['name'],
                'password' => $password,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }

        $admin = User::firstOrNew(['email' => self::SUPER_ADMIN['email']]);
        $admin->forceFill([
            'name' => self::SUPER_ADMIN['name'],
            'password' => $password,
            'is_super_admin' => true,
            'email_verified_at' => $admin->email_verified_at ?? now(),
        ])->save();

        $this->command?->table(
            ['Role', 'Email', 'Password'],
            [
                ['Super Admin', self::SUPER_ADMIN['email'], self::DEFAULT_PASSWORD],
                ...array_map(fn (array $a) => [$a['role']->label(), $a['email'], self::DEFAULT_PASSWORD], self::accounts()),
            ],
        );
    }
}
