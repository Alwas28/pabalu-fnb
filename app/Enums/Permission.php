<?php

namespace App\Enums;

/**
 * Izin (permission) aplikasi F&B.
 *
 * Nilai enum dipakai langsung sebagai nama ability Gate, sehingga bisa dipakai
 * di Blade (@can('menu.manage')), policy, maupun middleware route ('can:menu.manage').
 */
enum Permission: string
{
    // Penjualan & operasional
    case UsePos = 'pos.use';
    case ViewOrders = 'orders.view';
    case ApproveTransactions = 'transactions.approve';
    case ViewKitchen = 'kitchen.view';
    case ManageTables = 'tables.manage';
    case ManageShifts = 'shifts.manage';

    // Menu
    case ManageMenu = 'menu.manage';
    case ManageRecipes = 'recipes.manage';

    // Persediaan
    case ManageInventory = 'inventory.manage';
    case ManagePurchasing = 'purchasing.manage';

    // Bisnis
    case ManageCustomers = 'customers.manage';
    case ManageEmployees = 'employees.manage';
    case ViewFinance = 'finance.view';
    case ViewReports = 'reports.view';
    case ManageSettings = 'settings.manage';
    case ManagePaymentGateway = 'payment-gateway.manage';

    // Hak khusus owner
    case ApproveRefunds = 'refunds.approve';

    public function label(): string
    {
        return match ($this) {
            self::UsePos => 'Menggunakan kasir (POS)',
            self::ViewOrders => 'Melihat pesanan & transaksi',
            self::ApproveTransactions => 'Menyetujui void, refund & diskon khusus',
            self::ViewKitchen => 'Melihat & memperbarui antrean dapur',
            self::ManageTables => 'Mengelola meja & area',
            self::ManageShifts => 'Membuka & menutup shift kasir',
            self::ManageMenu => 'Mengelola menu & kategori',
            self::ManageRecipes => 'Mengelola resep & bahan baku',
            self::ManageInventory => 'Mengelola stok bahan',
            self::ManagePurchasing => 'Mengelola pembelian & supplier',
            self::ManageCustomers => 'Mengelola pelanggan',
            self::ManageEmployees => 'Mengelola karyawan & pengguna',
            self::ViewFinance => 'Melihat keuangan',
            self::ViewReports => 'Melihat laporan',
            self::ManageSettings => 'Mengelola bisnis & outlet',
            self::ManagePaymentGateway => 'Mengatur payment gateway',
            self::ApproveRefunds => 'Menyetujui void & refund pesanan lunas',
        };
    }
}
