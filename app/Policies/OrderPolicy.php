<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;

/**
 * Order hanya terlihat oleh pengguna dengan izin melihat pesanan
 * di outlet yang ditugaskan kepadanya (Blueprint Khusus F&B bagian 3 & 14).
 */
class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->hasPermission(Permission::ViewOrders)
            && $user->canAccessOutlet($order->outlet);
    }

    /** Aksi kasir pada open bill: tambah item, pindah meja, pelunasan. */
    public function operate(User $user, Order $order): bool
    {
        return $user->can('usePos', $order->outlet);
    }

    /** Membatalkan open bill: hak sensitif Supervisor ke atas. */
    public function cancel(User $user, Order $order): bool
    {
        return $this->operate($user, $order) && $user->hasPermission(Permission::ApproveTransactions);
    }
}
