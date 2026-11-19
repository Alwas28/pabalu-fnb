<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;

/**
 * Menu sidebar mengikuti modul Web Management F&B (Blueprint Khusus F&B, bagian 4),
 * disaring berdasarkan permission role pengguna.
 *
 * - 'permission' => null  : tampil untuk semua pengguna yang login.
 * - 'route' => null       : modul belum dibangun (ditandai "Segera").
 * - 'active' (opsional)    : pola nama route yang membuat item aktif, mis. 'settings.*'.
 *   Saat route dibuat, lindungi juga dengan ->middleware('can:<permission>').
 */
class Navigation
{
    /**
     * @return array<string, list<array{label: string, icon: string, route: ?string, permission: ?Permission}>>
     */
    public static function all(): array
    {
        return [
            'Utama' => [
                ['label' => 'Dashboard', 'icon' => 'fa-chart-pie', 'route' => 'dashboard', 'permission' => null],
            ],
            'Penjualan' => [
                ['label' => 'Kasir (POS)', 'icon' => 'fa-cash-register', 'route' => 'pos', 'permission' => Permission::UsePos],
                ['label' => 'Pesanan', 'icon' => 'fa-receipt', 'route' => 'orders.index', 'active' => 'orders.*', 'permission' => Permission::ViewOrders],
                ['label' => 'Antrean Dapur', 'icon' => 'fa-fire-burner', 'route' => 'kitchen.index', 'permission' => Permission::ViewKitchen],
                ['label' => 'Meja & Area', 'icon' => 'fa-chair', 'route' => 'tables.index', 'permission' => Permission::ManageTables],
                ['label' => 'Persetujuan', 'icon' => 'fa-stamp', 'route' => 'approvals.index', 'permission' => Permission::ApproveRefunds],
                ['label' => 'Shift Kasir', 'icon' => 'fa-clock', 'route' => 'shifts.index', 'active' => 'shifts.*', 'permission' => Permission::ManageShifts],
            ],
            'Menu' => [
                ['label' => 'Daftar Menu', 'icon' => 'fa-utensils', 'route' => 'menu.items.index', 'active' => 'menu.items.*', 'permission' => Permission::ManageMenu],
                ['label' => 'Kategori', 'icon' => 'fa-tags', 'route' => 'menu.categories', 'permission' => Permission::ManageMenu],
                ['label' => 'Modifier & Topping', 'icon' => 'fa-layer-group', 'route' => 'menu.modifiers', 'permission' => Permission::ManageMenu],
                ['label' => 'Resep & Bahan', 'icon' => 'fa-book-open', 'route' => 'recipes.index', 'permission' => Permission::ManageRecipes],
            ],
            'Persediaan' => [
                ['label' => 'Stok Bahan', 'icon' => 'fa-warehouse', 'route' => 'inventory.index', 'active' => 'inventory.*', 'permission' => Permission::ManageInventory],
                ['label' => 'Pembelian', 'icon' => 'fa-truck', 'route' => 'purchasing.index', 'active' => 'purchasing.*', 'permission' => Permission::ManagePurchasing],
            ],
            'Bisnis' => [
                ['label' => 'Pelanggan', 'icon' => 'fa-users', 'route' => 'customers.index', 'permission' => Permission::ManageCustomers],
                ['label' => 'Karyawan', 'icon' => 'fa-user-group', 'route' => 'employees.index', 'permission' => Permission::ManageEmployees],
                ['label' => 'Keuangan', 'icon' => 'fa-wallet', 'route' => 'finance.index', 'permission' => Permission::ViewFinance],
                ['label' => 'Pengeluaran', 'icon' => 'fa-money-bill-wave', 'route' => 'expenses.index', 'permission' => Permission::ViewFinance],
                ['label' => 'Laporan', 'icon' => 'fa-chart-line', 'route' => 'reports.index', 'permission' => Permission::ViewReports],
            ],
            'Pengaturan' => [
                ['label' => 'Bisnis & Outlet', 'icon' => 'fa-shop', 'route' => 'settings.business', 'active' => ['settings.business', 'settings.outlets.*'], 'permission' => Permission::ManageSettings],
                ...(config('features.role_permissions')
                    ? [['label' => 'Hak Akses Role', 'icon' => 'fa-user-shield', 'route' => 'settings.roles', 'permission' => Permission::ManageRoles]]
                    : []),
                ['label' => 'Riwayat Perubahan', 'icon' => 'fa-clock-rotate-left', 'route' => 'settings.audit', 'permission' => Permission::ManageSettings],
                ['label' => 'Akun', 'icon' => 'fa-user-gear', 'route' => 'profile', 'permission' => null],
            ],
        ];
    }

    /**
     * Menu yang boleh dilihat pengguna; grup kosong dihilangkan.
     *
     * @return array<string, list<array{label: string, icon: string, route: ?string, permission: ?Permission}>>
     */
    public static function for(User $user): array
    {
        $menu = [];

        foreach (self::all() as $group => $items) {
            $visible = array_values(array_filter(
                $items,
                fn (array $item) => $item['permission'] === null || $user->hasPermission($item['permission']),
            ));

            if ($visible !== []) {
                $menu[$group] = $visible;
            }
        }

        return $menu;
    }

    /**
     * Pintasan dashboard: modul pertama yang boleh diakses (selain Dashboard & Akun).
     *
     * @return list<array{label: string, icon: string, route: ?string, permission: ?Permission}>
     */
    public static function shortcuts(User $user, int $limit = 4): array
    {
        $items = array_merge(...array_values(self::for($user)));
        $items = array_filter($items, fn (array $item) => $item['permission'] !== null);

        return array_slice(array_values($items), 0, $limit);
    }
}
