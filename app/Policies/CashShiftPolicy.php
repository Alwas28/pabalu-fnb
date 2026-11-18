<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CashShift;
use App\Models\User;

/**
 * Kasir hanya melihat & mengelola shift miliknya. Supervisor ke atas (izin menyetujui transaksi)
 * dan pemegang izin laporan boleh melihat shift kasir lain di outlet yang ditugaskan;
 * Supervisor ke atas juga boleh menutup shift kasir lain (mis. kasir lupa menutup).
 */
class CashShiftPolicy
{
    public function view(User $user, CashShift $shift): bool
    {
        if (! $user->canAccessOutlet($shift->outlet)) {
            return false;
        }

        return $shift->user_id === $user->id
            || $user->hasPermission(Permission::ApproveTransactions)
            || $user->hasPermission(Permission::ViewReports);
    }

    /** Kas masuk/keluar & tutup shift. */
    public function update(User $user, CashShift $shift): bool
    {
        if (! $user->canAccessOutlet($shift->outlet)) {
            return false;
        }

        return ($shift->user_id === $user->id && $user->hasPermission(Permission::ManageShifts))
            || $user->hasPermission(Permission::ApproveTransactions);
    }

    /** Melihat shift seluruh kasir (bukan hanya milik sendiri). */
    public static function seesAllShifts(User $user): bool
    {
        return $user->hasPermission(Permission::ApproveTransactions) || $user->hasPermission(Permission::ViewReports);
    }
}
