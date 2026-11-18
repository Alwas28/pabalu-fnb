<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(UserRole $role): User
    {
        return User::factory()->role($role)->create();
    }

    public function test_owner_has_every_permission(): void
    {
        $owner = $this->userWithRole(UserRole::Owner);

        foreach (Permission::cases() as $permission) {
            $this->assertTrue(Gate::forUser($owner)->allows($permission->value), $permission->value);
        }
    }

    public function test_kasir_cannot_access_finance_reports_or_master_data(): void
    {
        $kasir = $this->userWithRole(UserRole::Kasir);

        $this->assertTrue(Gate::forUser($kasir)->allows('pos.use'));
        $this->assertTrue(Gate::forUser($kasir)->allows('shifts.manage'));

        foreach (['finance.view', 'reports.view', 'menu.manage', 'transactions.approve', 'inventory.manage', 'settings.manage'] as $ability) {
            $this->assertFalse(Gate::forUser($kasir)->allows($ability), $ability);
        }
    }

    public function test_only_supervisor_manager_and_owner_can_approve_sensitive_transactions(): void
    {
        $allowed = collect(UserRole::cases())
            ->filter(fn (UserRole $role) => $role->can(Permission::ApproveTransactions))
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            [UserRole::Owner, UserRole::ManagerOutlet, UserRole::Supervisor],
            $allowed,
        );
    }

    /**
     * @return array<string, array{UserRole, list<string>, list<string>}>
     */
    public static function sidebarProvider(): array
    {
        return [
            'owner' => [UserRole::Owner, ['Kasir (POS)', 'Keuangan', 'Laporan', 'Bisnis &amp; Outlet', 'Antrean Dapur'], []],
            'business admin' => [UserRole::BusinessAdmin, ['Daftar Menu', 'Karyawan', 'Pembelian', 'Bisnis &amp; Outlet'], ['Kasir (POS)', 'Keuangan', 'Laporan']],
            'manager' => [UserRole::ManagerOutlet, ['Kasir (POS)', 'Stok Bahan', 'Laporan', 'Daftar Menu'], ['Keuangan', 'Karyawan', 'Bisnis &amp; Outlet']],
            'supervisor' => [UserRole::Supervisor, ['Kasir (POS)', 'Pesanan', 'Antrean Dapur'], ['Daftar Menu', 'Laporan', 'Keuangan']],
            'kasir' => [UserRole::Kasir, ['Kasir (POS)', 'Pesanan', 'Shift Kasir'], ['Antrean Dapur', 'Daftar Menu', 'Laporan', 'Keuangan', 'Stok Bahan']],
            'kitchen' => [UserRole::KitchenStaff, ['Antrean Dapur'], ['Kasir (POS)', 'Pesanan', 'Daftar Menu', 'Laporan']],
            'inventory' => [UserRole::InventoryStaff, ['Stok Bahan', 'Pembelian'], ['Kasir (POS)', 'Daftar Menu', 'Laporan', 'Keuangan']],
        ];
    }

    /**
     * @param  list<string>  $visible
     * @param  list<string>  $hidden
     */
    #[DataProvider('sidebarProvider')]
    public function test_sidebar_only_shows_permitted_modules(UserRole $role, array $visible, array $hidden): void
    {
        $response = $this->actingAs($this->userWithRole($role))->get('/dashboard')->assertOk();

        foreach ($visible as $label) {
            $response->assertSee($label, false);
        }

        foreach ($hidden as $label) {
            $response->assertDontSee('</i>'.$label, false);
        }

        // Dashboard & Akun selalu ada.
        $response->assertSee('Dashboard')->assertSee('Akun');
    }

    public function test_empty_menu_groups_are_removed(): void
    {
        $menu = Navigation::for($this->userWithRole(UserRole::KitchenStaff));

        $this->assertSame(['Utama', 'Penjualan', 'Pengaturan'], array_keys($menu));
    }

    /**
     * @return array<string, array{UserRole, list<string>, list<string>}>
     */
    public static function dashboardProvider(): array
    {
        return [
            'owner' => [UserRole::Owner, ['Penjualan Bersih Bulan Ini', 'Laba Kotor', 'Performa Outlet', 'Grafik Penjualan'], ['Antrean Dapur</div>', 'Ringkasan Shift']],
            'business admin' => [UserRole::BusinessAdmin, ['Ketersediaan Menu', 'Tim', 'Tambah Menu'], ['Penjualan Bersih', 'Laba Kotor', 'Grafik Penjualan']],
            'manager' => [UserRole::ManagerOutlet, ['Penjualan Hari Ini', 'Grafik Penjualan', 'Stok Bahan Kritis'], ['Laba Kotor', 'Performa Outlet']],
            'supervisor' => [UserRole::Supervisor, ['Menunggu Persetujuan', 'Order Terbaru'], ['Laba Kotor', 'Grafik Penjualan']],
            'kasir' => [UserRole::Kasir, ['Ringkasan Shift', 'Order Shift Ini', 'Buka Kasir'], ['Laba Kotor', 'Grafik Penjualan', 'Performa Outlet', 'Menunggu Persetujuan']],
            'kitchen' => [UserRole::KitchenStaff, ['Sedang Dimasak', 'Mulai Masak'], ['Rp ', 'Order Baru', 'Laba Kotor']],
            'inventory' => [UserRole::InventoryStaff, ['Purchase Order', 'Stok Bahan Kritis', 'Terima Barang'], ['Laba Kotor', 'Grafik Penjualan', 'Order Terbaru']],
        ];
    }

    /**
     * @param  list<string>  $visible
     * @param  list<string>  $hidden
     */
    #[DataProvider('dashboardProvider')]
    public function test_dashboard_content_matches_role(UserRole $role, array $visible, array $hidden): void
    {
        $response = $this->actingAs($this->userWithRole($role))->get('/dashboard')->assertOk();

        foreach ($visible as $text) {
            $response->assertSee($text, false);
        }

        foreach ($hidden as $text) {
            $response->assertDontSee($text, false);
        }
    }

    public function test_restock_button_requires_purchasing_permission(): void
    {
        // Manager punya izin pembelian → tombol Restock tampil.
        $this->actingAs($this->userWithRole(UserRole::ManagerOutlet))
            ->get('/dashboard')
            ->assertSee('Restock');
    }
}
