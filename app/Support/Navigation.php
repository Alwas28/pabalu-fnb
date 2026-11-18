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
                ['label' => 'Kasir (POS)', 'icon' => 'fa-cash-register', 'route' => null, 'permission' => Permission::UsePos],
                ['label' => 'Pesanan', 'icon' => 'fa-receipt', 'route' => null, 'permission' => Permission::ViewOrders],
                ['label' => 'Antrean Dapur', 'icon' => 'fa-fire-burner', 'route' => null, 'permission' => Permission::ViewKitchen],
                ['label' => 'Meja & Area', 'icon' => 'fa-chair', 'route' => null, 'permission' => Permission::ManageTables],
                ['label' => 'Shift Kasir', 'icon' => 'fa-clock', 'route' => null, 'permission' => Permission::ManageShifts],
            ],
            'Menu' => [
                ['label' => 'Daftar Menu', 'icon' => 'fa-utensils', 'route' => null, 'permission' => Permission::ManageMenu],
                ['label' => 'Kategori', 'icon' => 'fa-tags', 'route' => null, 'permission' => Permission::ManageMenu],
                ['label' => 'Resep & Bahan', 'icon' => 'fa-book-open', 'route' => null, 'permission' => Permission::ManageRecipes],
            ],
            'Persediaan' => [
                ['label' => 'Stok Bahan', 'icon' => 'fa-warehouse', 'route' => null, 'permission' => Permission::ManageInventory],
                ['label' => 'Pembelian', 'icon' => 'fa-truck', 'route' => null, 'permission' => Permission::ManagePurchasing],
            ],
            'Bisnis' => [
                ['label' => 'Pelanggan', 'icon' => 'fa-users', 'route' => null, 'permission' => Permission::ManageCustomers],
                ['label' => 'Karyawan', 'icon' => 'fa-user-group', 'route' => null, 'permission' => Permission::ManageEmployees],
                ['label' => 'Keuangan', 'icon' => 'fa-wallet', 'route' => null, 'permission' => Permission::ViewFinance],
                ['label' => 'Laporan', 'icon' => 'fa-chart-line', 'route' => null, 'permission' => Permission::ViewReports],
            ],
            'Pengaturan' => [
                ['label' => 'Bisnis & Outlet', 'icon' => 'fa-shop', 'route' => 'settings.business', 'active' => 'settings.*', 'permission' => Permission::ManageSettings],
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
