<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Outlet;
use App\Models\User;

/**
 * Akses outlet selalu dibatasi pada bisnis aktif & penugasan pengguna
 * (Blueprint Khusus F&B bagian 3 & 14).
 */
class OutletPolicy
{
    /** Melihat data / konfigurasi outlet (mis. dari POS). */
    public function view(User $user, Outlet $outlet): bool
    {
        return $user->canAccessOutlet($outlet);
    }

    /** Membuat outlet baru pada bisnis aktif. */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageSettings);
    }

    /** Mengubah pengaturan outlet. */
    public function update(User $user, Outlet $outlet): bool
    {
        return $user->hasPermission(Permission::ManageSettings)
            && $outlet->business_id === $user->activeBusiness()?->id;
    }

    /** Menggunakan kasir (POS) di outlet ini. */
    public function usePos(User $user, Outlet $outlet): bool
    {
        return $user->hasPermission(Permission::UsePos)
            && $outlet->is_active
            && $user->canAccessOutlet($outlet);
    }
}
