<?php

namespace App\Support\Admin;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\AdminActivity;
use App\Models\Business;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Tindakan super admin terhadap seluruh aplikasi. Setiap tindakan dicatat di admin_activities.
 *
 * - Akun super admin tidak bisa dinonaktifkan dari panel (termasuk dirinya sendiri),
 *   agar pengelola aplikasi tidak terkunci.
 * - Menonaktifkan akun mencabut semua token aplikasi kasir; sesi web berakhir pada permintaan berikutnya.
 */
class PlatformAdmin
{
    /**
     * @throws ValidationException
     */
    public function deactivateUser(User $user, string $reason): void
    {
        $reason = $this->reason($reason);

        if ($user->isSuperAdmin()) {
            throw ValidationException::withMessages(['user' => 'Akun super admin tidak bisa dinonaktifkan dari panel.']);
        }

        if ($user->isDeactivated()) {
            return;
        }

        DB::transaction(function () use ($user, $reason) {
            $user->forceFill(['deactivated_at' => now(), 'deactivation_reason' => $reason])->save();
            $user->tokens()->delete();

            AdminActivity::record('user.deactivated', $user, "{$user->name} ({$user->email})", ['alasan' => $reason]);
        });
    }

    public function activateUser(User $user): void
    {
        if (! $user->isDeactivated()) {
            return;
        }

        $user->forceFill(['deactivated_at' => null, 'deactivation_reason' => null])->save();
        AdminActivity::record('user.activated', $user, "{$user->name} ({$user->email})");
    }

    /**
     * @throws ValidationException
     */
    public function suspendBusiness(Business $business, string $reason): void
    {
        $reason = $this->reason($reason);

        if ($business->isSuspended()) {
            return;
        }

        $business->forceFill(['suspended_at' => now(), 'suspension_reason' => $reason])->save();
        AdminActivity::record('business.suspended', $business, $business->name, ['alasan' => $reason]);
    }

    public function reactivateBusiness(Business $business): void
    {
        if (! $business->isSuspended()) {
            return;
        }

        $business->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
        AdminActivity::record('business.reactivated', $business, $business->name);
    }

    /** Kirim link reset password ke email pengguna. */
    public function sendPasswordReset(User $user): string
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_LINK_SENT) {
            AdminActivity::record('user.password_reset', $user, "{$user->name} ({$user->email})");
        }

        return __($status);
    }

    /**
     * Simpan izin bawaan role untuk seluruh aplikasi. Role yang sama dengan bawaan sistem tidak disimpan.
     *
     * @param  array<string, list<string>>  $selected  [role => [nilai Permission, …]]
     */
    public function saveRolePermissions(array $selected): void
    {
        $stored = [];
        $changes = [];
        $current = PlatformSetting::get(PlatformSetting::ROLE_PERMISSIONS, []);

        foreach (self::configurableRoles() as $role) {
            $values = collect($selected[$role->value] ?? [])
                ->filter(fn ($value) => in_array(Permission::tryFrom($value), self::grantablePermissions(), true))
                ->unique()->sort()->values()->all();
            $default = self::defaultPermissions($role);
            $before = collect($current[$role->value] ?? $default)->sort()->values()->all();

            if ($values !== $default) {
                $stored[$role->value] = $values;
            }

            $added = array_values(array_diff($values, $before));
            $removed = array_values(array_diff($before, $values));

            if ($added || $removed) {
                $label = fn (array $list) => collect($list)->map(fn ($v) => Permission::from($v)->label())->join(', ') ?: '—';
                $changes[$role->label()] = ['ditambah' => $label($added), 'dicabut' => $label($removed)];
            }
        }

        PlatformSetting::put(PlatformSetting::ROLE_PERMISSIONS, $stored ?: null);

        if ($changes) {
            AdminActivity::record('roles.updated', null, 'Izin bawaan role', $changes);
        }
    }

    /** @return list<UserRole> */
    public static function configurableRoles(): array
    {
        return array_values(array_filter(UserRole::cases(), fn (UserRole $role) => $role->isConfigurable()));
    }

    /**
     * Izin yang boleh diberikan ke role selain owner.
     *
     * @return list<Permission>
     */
    public static function grantablePermissions(): array
    {
        return array_values(array_filter(Permission::cases(), fn (Permission $p) => ! in_array($p, UserRole::ownerOnlyPermissions(), true)));
    }

    /** @return list<string> */
    public static function defaultPermissions(UserRole $role): array
    {
        return collect($role->permissions())
            ->filter(fn (Permission $p) => in_array($p, self::grantablePermissions(), true))
            ->map(fn (Permission $p) => $p->value)
            ->sort()->values()->all();
    }

    /**
     * @throws ValidationException
     */
    private function reason(string $reason): string
    {
        return Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan'])
            ->validate()['reason'];
    }
}
