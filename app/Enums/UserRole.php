<?php

namespace App\Enums;

/**
 * Role pengguna aplikasi F&B (Blueprint Khusus F&B, bagian 3).
 *
 * Catatan: untuk sementara role disimpan di tabel users. Sesuai blueprint,
 * role nantinya melekat pada relasi user ↔ bisnis (business_users) dan
 * dibatasi per business_id / outlet_id.
 */
enum UserRole: string
{
    case Owner = 'owner';
    case BusinessAdmin = 'business_admin';
    case ManagerOutlet = 'manager_outlet';
    case Supervisor = 'supervisor';
    case Kasir = 'kasir';
    case KitchenStaff = 'kitchen_staff';
    case InventoryStaff = 'inventory_staff';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::BusinessAdmin => 'Business Admin',
            self::ManagerOutlet => 'Manager Outlet',
            self::Supervisor => 'Supervisor',
            self::Kasir => 'Kasir',
            self::KitchenStaff => 'Kitchen Staff',
            self::InventoryStaff => 'Inventory Staff',
        };
    }

    /**
     * Matriks role → permission.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Mengelola keseluruhan bisnis F&B dan outlet.
            self::Owner => Permission::cases(),

            // Menu, pengguna, supplier, pengaturan.
            self::BusinessAdmin => [
                Permission::ManageMenu,
                Permission::ManageRecipes,
                Permission::ManageTables,
                Permission::ManagePurchasing,
                Permission::ManageCustomers,
                Permission::ManageEmployees,
                Permission::ManageSettings,
            ],

            // Laporan outlet, stok, shift, pesanan (+ kelola menu, kriteria keberhasilan bagian 17).
            self::ManagerOutlet => [
                Permission::UsePos,
                Permission::ViewOrders,
                Permission::ApproveTransactions,
                Permission::ViewKitchen,
                Permission::ManageTables,
                Permission::ManageShifts,
                Permission::ManageMenu,
                Permission::ManageRecipes,
                Permission::ManageInventory,
                Permission::ManagePurchasing,
                Permission::ManageCustomers,
                Permission::ViewReports,
            ],

            // Mengawasi transaksi & operasional harian; persetujuan diskon/void.
            self::Supervisor => [
                Permission::UsePos,
                Permission::ViewOrders,
                Permission::ApproveTransactions,
                Permission::ViewKitchen,
                Permission::ManageShifts,
            ],

            // Buat order, bayar, cetak/kirim struk.
            self::Kasir => [
                Permission::UsePos,
                Permission::ViewOrders,
                Permission::ManageShifts,
            ],

            // Melihat dan memperbarui status item pesanan.
            self::KitchenStaff => [
                Permission::ViewKitchen,
            ],

            // Penerimaan, transfer, stok opname.
            self::InventoryStaff => [
                Permission::ManageInventory,
                Permission::ManagePurchasing,
            ],
        };
    }

    /**
     * Role yang otomatis berhak atas semua outlet bisnis (tanpa penugasan per outlet).
     */
    public function coversAllOutlets(): bool
    {
        return in_array($this, [self::Owner, self::BusinessAdmin], true);
    }

    /**
     * Role yang boleh diberikan / dikelola oleh role ini lewat modul Karyawan.
     * Owner tidak pernah bisa diberikan (melekat pada pemilik bisnis);
     * Business Admin hanya mengelola role di bawahnya.
     *
     * @return list<self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::Owner => array_values(array_filter(self::cases(), fn (self $r) => $r !== self::Owner)),
            self::BusinessAdmin => array_values(array_filter(
                self::cases(),
                fn (self $r) => ! in_array($r, [self::Owner, self::BusinessAdmin], true),
            )),
            default => [],
        };
    }

    public function canAssign(self $role): bool
    {
        return in_array($role, $this->assignableRoles(), true);
    }

    /**
     * Izin yang hanya boleh dimiliki Owner (tidak bisa diberikan ke role lain lewat pengaturan).
     *
     * @return list<Permission>
     */
    public static function ownerOnlyPermissions(): array
    {
        return [Permission::ApproveRefunds, Permission::ManagePaymentGateway, Permission::ManageRoles];
    }

    /** Role yang izinnya boleh diatur owner (semua kecuali Owner). */
    public function isConfigurable(): bool
    {
        return $this !== self::Owner;
    }

    /** Izin bawaan — dipakai bila bisnis belum mengatur izin role ini. */
    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
