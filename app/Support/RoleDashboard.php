<?php

namespace App\Support;

use App\Enums\KitchenItemStatus;
use App\Enums\UserRole;
use App\Models\CashShift;
use App\Models\Order;
use App\Models\OrderRefund;

/**
 * Susunan dashboard per role (Blueprint Khusus F&B, bagian 3 & 9): KPI & widget yang relevan
 * dengan tanggung jawab tiap role, dihitung dari data nyata lewat DashboardData.
 */
class RoleDashboard
{
    /**
     * @return array{
     *     subtitle: string,
     *     action: ?array{label: string, icon: string, route: string},
     *     stats: list<array{label: string, value: string, icon: string, color: ?string, note: string, up: ?bool}>,
     *     rows: list<list<string>>
     * }
     */
    public static function for(DashboardData $data): array
    {
        $outletName = $data->outlet?->name ?? 'outlet';

        return match ($data->role) {
            UserRole::Owner => self::owner($data),
            UserRole::BusinessAdmin => self::admin($data),
            UserRole::ManagerOutlet => self::manager($data, $outletName),
            UserRole::Supervisor => self::supervisor($data),
            UserRole::Kasir => self::kasir($data, $outletName),
            UserRole::KitchenStaff => self::kitchen($data),
            UserRole::InventoryStaff => self::inventory($data, $outletName),
        };
    }

    private static function owner(DashboardData $data): array
    {
        $now = $data->metrics($data->monthToDate())->summary();
        $before = $data->metrics($data->previous($data->monthToDate(), true))->summary();
        $vs = 'vs bulan lalu';

        return [
            'subtitle' => 'Ringkasan seluruh outlet bulan ini',
            'action' => ['label' => 'Order Baru', 'icon' => 'fa-plus', 'route' => 'pos'],
            'stats' => [
                self::stat('Penjualan Bersih Bulan Ini', DashboardData::compactMoney($now['net_sales']), 'fa-money-bill-trend-up', null, ...DashboardData::trend($now['net_sales'], $before['net_sales'], $vs)),
                self::stat('Jumlah Order', number_format($now['orders'], 0, ',', '.'), 'fa-receipt', '#818cf8', ...DashboardData::trend($now['orders'], $before['orders'], $vs)),
                self::stat('Rata-rata per Order (AOV)', Money::format($now['average_order']), 'fa-scale-balanced', '#34d399', ...DashboardData::trend($now['average_order'], $before['average_order'], $vs)),
                self::stat('Laba Kotor (estimasi)', DashboardData::compactMoney($now['gross_profit']), 'fa-sack-dollar', '#f87171', ...DashboardData::trend($now['gross_profit'], $before['gross_profit'], $vs)),
            ],
            'rows' => [['sales-chart', 'top-menu'], ['orders', 'stock-alert'], ['outlets']],
        ];
    }

    private static function admin(DashboardData $data): array
    {
        $business = $data->business;
        $soldOut = $data->outlet
            ? $business->menuItems()->where('is_active', true)->with(['outlets' => fn ($q) => $q->whereKey($data->outlet->id)])->get()->reject->isAvailableAt($data->outlet)->count()
            : 0;
        $outlets = $business->outlets()->get();

        return [
            'subtitle' => 'Konfigurasi menu, pengguna & supplier',
            'action' => ['label' => 'Tambah Menu', 'icon' => 'fa-plus', 'route' => 'menu.items.create'],
            'stats' => [
                self::stat('Menu Aktif', (string) $business->menuItems()->where('is_active', true)->count(), 'fa-utensils', null, $soldOut.' menu habis di '.($data->outlet?->name ?? 'outlet')),
                self::stat('Kategori', (string) $business->menuCategories()->count(), 'fa-tags', '#818cf8', $business->modifierGroups()->count().' grup modifier'),
                self::stat('Supplier Aktif', (string) $business->suppliers()->where('is_active', true)->count(), 'fa-truck', '#34d399', $business->ingredients()->where('is_active', true)->count().' bahan baku'),
                self::stat('Outlet', (string) $outlets->count(), 'fa-shop', '#f87171', $outlets->pluck('name')->map(fn ($n) => str_replace('Outlet ', '', $n))->join(', ')),
            ],
            'rows' => [['menu-availability', 'team']],
        ];
    }

    private static function manager(DashboardData $data, string $outletName): array
    {
        $now = $data->metrics($data->today())->summary();
        $before = $data->metrics($data->previous($data->today(), false))->summary();
        $shifts = CashShift::open()->whereIn('outlet_id', $data->outletIds)->with('user')->get();

        return [
            'subtitle' => 'Operasional '.$outletName.' hari ini',
            'action' => ['label' => 'Order Baru', 'icon' => 'fa-plus', 'route' => 'pos'],
            'stats' => [
                self::stat('Penjualan Hari Ini', DashboardData::compactMoney($now['net_sales']), 'fa-money-bill-trend-up', null, ...DashboardData::trend($now['net_sales'], $before['net_sales'], 'vs kemarin')),
                self::stat('Order Hari Ini', (string) $now['orders'], 'fa-receipt', '#818cf8', ...DashboardData::trend($now['orders'], $before['orders'], 'vs kemarin')),
                self::stat('Rata-rata per Order (AOV)', Money::format($now['average_order']), 'fa-scale-balanced', '#34d399', ...DashboardData::trend($now['average_order'], $before['average_order'], 'vs kemarin')),
                self::stat('Shift Aktif', (string) $shifts->count(), 'fa-clock', '#f87171', $shifts->isEmpty() ? 'Belum ada shift dibuka' : 'Kasir: '.$shifts->map(fn ($s) => strtok($s->user->name, ' '))->join(', ')),
            ],
            'rows' => [['sales-chart', 'top-menu'], ['orders', 'stock-alert']],
        ];
    }

    private static function supervisor(DashboardData $data): array
    {
        $today = $data->today();
        $s = $data->metrics($today)->summary();
        $inKitchen = Order::whereIn('outlet_id', $data->outletIds)->inKitchen()->count();
        $openBills = Order::whereIn('outlet_id', $data->outletIds)->open()->count();
        $refunds = OrderRefund::whereHas('order', fn ($q) => $q->whereIn('outlet_id', $data->outletIds))
            ->whereBetween('created_at', $today->utcRange())->get();

        return [
            'subtitle' => 'Pengawasan transaksi hari ini',
            'action' => ['label' => 'Order Baru', 'icon' => 'fa-plus', 'route' => 'pos'],
            'stats' => [
                self::stat('Order Hari Ini', (string) $s['orders'], 'fa-receipt', null, Money::format($s['net_sales']).' penjualan bersih'),
                self::stat('Sedang Diproses', (string) $inKitchen, 'fa-fire-burner', '#818cf8', $openBills.' pesanan belum dibayar'),
                self::stat('Menunggu Persetujuan', (string) $data->pendingApprovalCount(), 'fa-user-check', '#fbbf24', 'Void & refund menunggu owner'),
                self::stat('Void/Refund Hari Ini', (string) $refunds->pluck('order_id')->unique()->count(), 'fa-rotate-left', '#f87171', Money::format((int) $refunds->sum('amount'))),
            ],
            'rows' => [['orders', 'approvals']],
        ];
    }

    private static function kasir(DashboardData $data, string $outletName): array
    {
        $shift = $data->kasirShift();
        $summary = $data->shiftSummary();
        $openBills = Order::whereIn('outlet_id', $data->outletIds)->open()->get(['table_number']);

        return [
            'subtitle' => $shift ? 'Shift kamu sedang berjalan' : 'Shift belum dibuka',
            'action' => ['label' => 'Buka Kasir', 'icon' => 'fa-cash-register', 'route' => 'pos'],
            'stats' => [
                self::stat('Status Shift', $shift ? 'Buka' : 'Tutup', 'fa-clock', $shift ? '#34d399' : '#f87171',
                    $shift ? 'Sejak '.$shift->opened_at->timezone($data->timezone)->format('H:i').' · '.$outletName : 'Buka shift dari halaman kasir'),
                self::stat('Penjualan Shift Ini', Money::format($summary['payments_total'] ?? 0), 'fa-money-bill-wave', null, ($summary['orders_paid'] ?? 0).' order dibayar'),
                self::stat('Order Belum Dibayar', (string) $openBills->count(), 'fa-hourglass-half', '#fbbf24',
                    $openBills->pluck('table_number')->filter()->isNotEmpty() ? 'Meja '.$openBills->pluck('table_number')->filter()->join(', ') : 'Pesanan bayar nanti'),
                self::stat('Kas Ekspektasi', Money::format($summary['expected_cash'] ?? 0), 'fa-cash-register', '#818cf8', 'Modal awal '.Money::format($summary['opening_cash'] ?? 0)),
            ],
            'rows' => [['orders', 'shift-summary']],
        ];
    }

    private static function kitchen(DashboardData $data): array
    {
        $counts = $data->kitchenCounts();
        $avg = $data->averageServeMinutes();

        return [
            'subtitle' => 'Antrean pesanan dapur',
            'action' => null,
            'stats' => [
                self::stat('Dalam Antrean', (string) ($counts[KitchenItemStatus::Queued->value] ?? 0), 'fa-list-ol', null, 'Porsi belum dimasak'),
                self::stat('Sedang Dimasak', (string) ($counts[KitchenItemStatus::Preparing->value] ?? 0), 'fa-fire-burner', '#f87171', 'Porsi di dapur'),
                self::stat('Siap Disajikan', (string) ($counts[KitchenItemStatus::Ready->value] ?? 0), 'fa-bell-concierge', '#34d399', 'Menunggu diantar'),
                self::stat('Rata-rata Waktu Saji', $avg === null ? '—' : $avg.' mnt', 'fa-stopwatch', '#818cf8', 'Target 15 mnt', $avg === null ? null : $avg <= 15),
            ],
            'rows' => [['kitchen-queue']],
        ];
    }

    private static function inventory(DashboardData $data, string $outletName): array
    {
        $lastCount = $data->lastStockCount();

        return [
            'subtitle' => 'Persediaan bahan baku',
            'action' => ['label' => 'Terima Barang', 'icon' => 'fa-truck-ramp-box', 'route' => 'purchasing.index'],
            'stats' => [
                self::stat('Bahan Kritis', (string) $data->lowStockCount(), 'fa-triangle-exclamation', '#f87171', 'Di bawah stok minimum'),
                self::stat('PO Menunggu Diterima', (string) $data->waitingPurchaseOrderCount(), 'fa-truck', null, $outletName),
                self::stat('Waste Minggu Ini', Money::format($data->wasteValueThisWeek()), 'fa-trash-can', '#fbbf24', 'Nilai bahan terbuang'),
                self::stat('Stok Opname Terakhir', $lastCount ? $lastCount->completed_at->timezone($data->timezone)->translatedFormat('d M') : '—', 'fa-clipboard-check', '#818cf8',
                    $lastCount ? $outletName : 'Belum pernah opname'),
            ],
            'rows' => [['purchase-orders', 'stock-alert']],
        ];
    }

    /**
     * @return array{label: string, value: string, icon: string, color: ?string, note: string, up: ?bool}
     */
    private static function stat(string $label, string $value, string $icon, ?string $color, string $note, ?bool $up = null): array
    {
        return compact('label', 'value', 'icon', 'color', 'note', 'up');
    }
}
