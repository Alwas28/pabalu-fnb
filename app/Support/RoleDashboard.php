<?php

namespace App\Support;

use App\Enums\UserRole;

/**
 * Susunan dashboard per role.
 *
 * Setiap role melihat KPI dan widget yang relevan dengan tanggung jawabnya
 * (Blueprint Khusus F&B, bagian 3 & 9). Angka masih DATA CONTOH sampai modul
 * transaksi, persediaan, dan analytics tersedia — kecuali widget "team" yang
 * membaca data pengguna asli.
 */
class RoleDashboard
{
    /**
     * @return array{
     *     subtitle: string,
     *     action: ?array{label: string, icon: string},
     *     stats: list<array{label: string, value: string, icon: string, color: ?string, note?: string, up?: bool}>,
     *     rows: list<list<string>>
     * }
     */
    public static function for(UserRole $role, string $outletName = 'Outlet'): array
    {
        return match ($role) {
            UserRole::Owner => [
                'subtitle' => 'Ringkasan seluruh outlet bulan ini',
                'action' => ['label' => 'Order Baru', 'icon' => 'fa-plus'],
                'stats' => [
                    self::stat('Penjualan Bersih Bulan Ini', 'Rp 48,7 Jt', 'fa-money-bill-trend-up', null, '+12,4% vs bulan lalu', true),
                    self::stat('Jumlah Order', '1.284', 'fa-receipt', '#818cf8', '+8,1% vs bulan lalu', true),
                    self::stat('Rata-rata per Order (AOV)', 'Rp 37.900', 'fa-scale-balanced', '#34d399', '+3,9% vs bulan lalu', true),
                    self::stat('Laba Kotor (estimasi)', 'Rp 29,2 Jt', 'fa-sack-dollar', '#f87171', '-1,2% vs bulan lalu', false),
                ],
                'rows' => [['sales-chart', 'top-menu'], ['orders', 'stock-alert'], ['outlets']],
            ],

            UserRole::BusinessAdmin => [
                'subtitle' => 'Konfigurasi menu, pengguna & supplier',
                'action' => ['label' => 'Tambah Menu', 'icon' => 'fa-plus'],
                'stats' => [
                    self::stat('Menu Aktif', '64', 'fa-utensils', null, '3 menu habis hari ini'),
                    self::stat('Kategori', '9', 'fa-tags', '#818cf8', '2 kategori musiman'),
                    self::stat('Supplier Aktif', '12', 'fa-truck', '#34d399', '1 supplier baru bulan ini'),
                    self::stat('Outlet', '3', 'fa-shop', '#f87171', 'Kendari, Baubau, Kolaka'),
                ],
                'rows' => [['menu-availability', 'team']],
            ],

            UserRole::ManagerOutlet => [
                'subtitle' => 'Operasional '.$outletName.' hari ini',
                'action' => ['label' => 'Order Baru', 'icon' => 'fa-plus'],
                'stats' => [
                    self::stat('Penjualan Hari Ini', 'Rp 3,4 Jt', 'fa-money-bill-trend-up', null, '+6,2% vs kemarin', true),
                    self::stat('Order Hari Ini', '92', 'fa-receipt', '#818cf8', '+4 vs kemarin', true),
                    self::stat('Rata-rata per Order (AOV)', 'Rp 36.900', 'fa-scale-balanced', '#34d399', '+1,8% vs kemarin', true),
                    self::stat('Shift Aktif', '2', 'fa-clock', '#f87171', 'Kasir: Dedi, Sari'),
                ],
                'rows' => [['sales-chart', 'top-menu'], ['orders', 'stock-alert']],
            ],

            UserRole::Supervisor => [
                'subtitle' => 'Pengawasan transaksi hari ini',
                'action' => ['label' => 'Order Baru', 'icon' => 'fa-plus'],
                'stats' => [
                    self::stat('Order Hari Ini', '92', 'fa-receipt', null, '+4 vs kemarin', true),
                    self::stat('Sedang Diproses', '7', 'fa-fire-burner', '#818cf8', '3 di antaranya dine-in'),
                    self::stat('Menunggu Persetujuan', '3', 'fa-user-check', '#fbbf24', 'Void, refund & diskon'),
                    self::stat('Void/Refund Hari Ini', '2', 'fa-rotate-left', '#f87171', 'Rp 44.000'),
                ],
                'rows' => [['orders', 'approvals']],
            ],

            UserRole::Kasir => [
                'subtitle' => 'Shift kamu sedang berjalan',
                'action' => ['label' => 'Buka Kasir', 'icon' => 'fa-cash-register'],
                'stats' => [
                    self::stat('Status Shift', 'Buka', 'fa-clock', '#34d399', 'Sejak 08:00 · '.$outletName),
                    self::stat('Penjualan Shift Ini', 'Rp 1,9 Jt', 'fa-money-bill-wave', null, '48 order'),
                    self::stat('Order Belum Dibayar', '3', 'fa-hourglass-half', '#fbbf24', 'Meja 2, 7, 9'),
                    self::stat('Kas Ekspektasi', 'Rp 1,4 Jt', 'fa-cash-register', '#818cf8', 'Modal awal Rp 300.000'),
                ],
                'rows' => [['orders', 'shift-summary']],
            ],

            UserRole::KitchenStaff => [
                'subtitle' => 'Antrean pesanan dapur',
                'action' => null,
                'stats' => [
                    self::stat('Dalam Antrean', '6', 'fa-list-ol', null, 'Item belum dimasak'),
                    self::stat('Sedang Dimasak', '4', 'fa-fire-burner', '#f87171', '2 order dine-in'),
                    self::stat('Siap Disajikan', '3', 'fa-bell-concierge', '#34d399', 'Menunggu pelayan'),
                    self::stat('Rata-rata Waktu Saji', '11 mnt', 'fa-stopwatch', '#818cf8', 'Target 15 mnt', true),
                ],
                'rows' => [['kitchen-queue']],
            ],

            UserRole::InventoryStaff => [
                'subtitle' => 'Persediaan bahan baku',
                'action' => ['label' => 'Terima Barang', 'icon' => 'fa-truck-ramp-box'],
                'stats' => [
                    self::stat('Bahan Kritis', '5', 'fa-triangle-exclamation', '#f87171', 'Di bawah stok minimum'),
                    self::stat('PO Menunggu Diterima', '3', 'fa-truck', null, '1 dijadwalkan hari ini'),
                    self::stat('Waste Minggu Ini', '2,4 kg', 'fa-trash-can', '#fbbf24', '-0,6 kg vs minggu lalu', true),
                    self::stat('Stok Opname Berikutnya', '25 Nov', 'fa-clipboard-check', '#818cf8', $outletName),
                ],
                'rows' => [['purchase-orders', 'stock-alert']],
            ],
        };
    }

    /**
     * @return array{label: string, value: string, icon: string, color: ?string, note: string, up: ?bool}
     */
    private static function stat(string $label, string $value, string $icon, ?string $color, string $note, ?bool $up = null): array
    {
        return compact('label', 'value', 'icon', 'color', 'note', 'up');
    }
}
