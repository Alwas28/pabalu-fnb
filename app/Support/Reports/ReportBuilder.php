<?php

namespace App\Support\Reports;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Enums\StockMovementType;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan (Blueprint Khusus F&B bagian 13). Setiap laporan menghasilkan tabel generik
 * {columns, rows, totals} yang dipakai bersama oleh tampilan web & ekspor CSV.
 * Definisi angka mengikuti FinanceMetrics.
 *
 * Tipe kolom: text, number, money, percent.
 */
class ReportBuilder
{
    public const REPORTS = [
        'daily' => 'Penjualan per Hari',
        'outlet' => 'Per Outlet',
        'cashier' => 'Per Kasir',
        'service' => 'Per Layanan',
        'menu' => 'Menu Terlaris',
        'category' => 'Per Kategori',
        'unsold' => 'Menu Tidak Laku',
        'payment' => 'Pembayaran',
        'adjustments' => 'Diskon, Refund & Void',
        'inventory' => 'Persediaan',
        'waste' => 'Waste & Selisih Opname',
        'purchasing' => 'Pembelian per Supplier',
        'profit' => 'Laba per Outlet',
    ];

    /** @param list<int> $outletIds */
    public function __construct(private int $businessId, private array $outletIds, private Period $period) {}

    /**
     * @return array{columns: list<array{0: string, 1: string}>, rows: list<list<mixed>>, totals: ?list<mixed>}
     */
    public function build(string $report): array
    {
        return match ($report) {
            'outlet' => $this->groupedSales('outlet_id', 'Outlet', fn ($ids) => Outlet::whereIn('id', $ids)->pluck('name', 'id')),
            'cashier' => $this->groupedSales('cashier_id', 'Kasir', fn ($ids) => User::whereIn('id', $ids)->pluck('name', 'id')),
            'service' => $this->groupedSales('service_type', 'Layanan', fn ($ids) => collect($ids)->mapWithKeys(fn ($v) => [$v => ServiceType::tryFrom($v)?->label() ?? $v])),
            'menu' => $this->menu(),
            'category' => $this->category(),
            'unsold' => $this->unsold(),
            'payment' => $this->payment(),
            'adjustments' => $this->adjustments(),
            'inventory' => $this->inventory(),
            'waste' => $this->waste(),
            'purchasing' => $this->purchasing(),
            'profit' => $this->profit(),
            default => $this->daily(),
        };
    }

    private function metrics(?array $outletIds = null): FinanceMetrics
    {
        return new FinanceMetrics($this->businessId, $outletIds ?? $this->outletIds, $this->period);
    }

    private function daily(): array
    {
        $rows = collect($this->metrics()->daily())
            ->map(fn ($d, $date) => [Carbon::parse($date)->translatedFormat('D, d M Y'), $d['orders'], $d['net_sales'], $d['total'],
                $d['orders'] ? (int) round($d['total'] / $d['orders']) : 0])
            ->values()->all();

        return [
            'columns' => [['Tanggal', 'text'], ['Transaksi', 'number'], ['Penjualan bersih', 'money'], ['Total tagihan', 'money'], ['Rata-rata / transaksi', 'money']],
            'rows' => $rows,
            'totals' => $this->totalsOf($rows, [1, 2, 3], 'Total'),
        ];
    }

    /** Penjualan dikelompokkan per kolom order (outlet, kasir, layanan). */
    private function groupedSales(string $column, string $label, callable $names): array
    {
        $data = $this->metrics()->sales()
            ->selectRaw("$column as g, count(*) as orders, sum(subtotal - discount_amount) as net, sum(total) as total,
                sum(case when total > 0 then refunded_amount * (subtotal - discount_amount) * 1.0 / total else 0 end) as refund_net,
                sum(discount_amount) as discount")
            ->groupBy($column)
            ->get();

        $map = $names($data->pluck('g')->filter()->all());

        $rows = $data->map(fn ($r) => [
            $map[$r->g] ?? '—',
            (int) $r->orders,
            (int) round($r->net - $r->refund_net),
            (int) $r->discount,
            (int) $r->total,
            (int) $r->orders ? (int) round($r->total / $r->orders) : 0,
        ])->sortByDesc(2)->values()->all();

        return [
            'columns' => [[$label, 'text'], ['Transaksi', 'number'], ['Penjualan bersih', 'money'], ['Diskon', 'money'], ['Total tagihan', 'money'], ['Rata-rata / transaksi', 'money']],
            'rows' => $rows,
            'totals' => $this->totalsOf($rows, [1, 2, 3, 4], 'Total'),
        ];
    }

    /** @return Collection<int, object> item terjual dari transaksi sah */
    private function soldItems(): Collection
    {
        return OrderItem::query()
            ->whereIn('order_id', $this->metrics()->sales()->select('id'))
            ->selectRaw('menu_item_id, name, sum(quantity) as qty, sum(line_total) as revenue, sum(cost_amount) as cost')
            ->groupBy('menu_item_id', 'name')
            ->get();
    }

    private function menu(): array
    {
        $items = $this->soldItems();
        $revenueTotal = max(1, (int) $items->sum('revenue'));

        $rows = $items->sortByDesc('qty')->values()->map(fn ($r, $i) => [
            $i + 1,
            $r->name,
            (int) $r->qty,
            (int) $r->revenue,
            round($r->revenue / $revenueTotal * 100, 1),
            (int) $r->cost,
            $r->revenue > 0 && $r->cost > 0 ? round($r->cost / $r->revenue * 100, 1) : null,
        ])->all();

        return [
            'columns' => [['#', 'number'], ['Menu', 'text'], ['Terjual', 'number'], ['Pendapatan', 'money'], ['Kontribusi', 'percent'], ['Biaya bahan', 'money'], ['Food cost', 'percent']],
            'rows' => $rows,
            'totals' => $this->totalsOf($rows, [2, 3, 5], 'Total', 1),
        ];
    }

    private function category(): array
    {
        $items = $this->soldItems();
        $categories = MenuItem::whereIn('id', $items->pluck('menu_item_id')->filter())->with('category')->get()->mapWithKeys(fn ($m) => [$m->id => $m->category?->name ?? 'Tanpa kategori']);
        $revenueTotal = max(1, (int) $items->sum('revenue'));

        $rows = $items->groupBy(fn ($r) => $categories[$r->menu_item_id] ?? 'Menu terhapus')
            ->map(fn ($group, $name) => [$name, (int) $group->sum('qty'), (int) $group->sum('revenue'), round($group->sum('revenue') / $revenueTotal * 100, 1)])
            ->sortByDesc(2)->values()->all();

        return [
            'columns' => [['Kategori', 'text'], ['Terjual', 'number'], ['Pendapatan', 'money'], ['Kontribusi', 'percent']],
            'rows' => $rows,
            'totals' => $this->totalsOf($rows, [1, 2], 'Total'),
        ];
    }

    private function unsold(): array
    {
        $sold = $this->soldItems()->pluck('menu_item_id')->filter()->all();

        $rows = MenuItem::where('business_id', $this->businessId)->where('is_active', true)->whereNotIn('id', $sold)
            ->with('category')->orderBy('name')->get()
            ->map(fn ($m) => [$m->name, $m->category?->name ?? 'Tanpa kategori', $m->price])->all();

        return [
            'columns' => [['Menu', 'text'], ['Kategori', 'text'], ['Harga dasar', 'money']],
            'rows' => $rows,
            'totals' => null,
        ];
    }

    private function payment(): array
    {
        $methods = $this->metrics()->paymentsByMethod();
        $netTotal = max(1, collect($methods)->sum('net'));

        $rows = collect($methods)->map(fn ($r, $m) => [PaymentMethod::tryFrom($m)?->label() ?? $m, $r['received'], $r['refunded'], $r['net'], round($r['net'] / $netTotal * 100, 1)])->values()->all();

        return [
            'columns' => [['Metode', 'text'], ['Diterima', 'money'], ['Refund', 'money'], ['Bersih', 'money'], ['Porsi', 'percent']],
            'rows' => $rows,
            'totals' => $this->totalsOf($rows, [1, 2, 3], 'Total'),
        ];
    }

    private function adjustments(): array
    {
        $base = Order::query()
            ->where('business_id', $this->businessId)
            ->whereIn('outlet_id', $this->outletIds)
            ->whereBetween('placed_at', $this->period->utcRange())
            ->with(['outlet', 'cashier']);

        $rows = (clone $base)
            ->where(fn ($q) => $q->where('discount_amount', '>', 0)->orWhere('refunded_amount', '>', 0)
                ->orWhere(fn ($q) => $q->where('status', OrderStatus::Cancelled->value)->where('paid_amount', '>', 0)))
            ->latest('placed_at')
            ->limit(500)
            ->get()
            ->map(fn (Order $o) => [
                $o->placed_at->timezone($this->period->timezone)->format('d/m/Y H:i'),
                $o->number,
                $o->outlet->name,
                $o->cashier?->name ?? '—',
                $o->status === OrderStatus::Cancelled ? 'Void' : ($o->refunded_amount > 0 ? 'Refund' : 'Diskon'),
                $o->discount_amount,
                $o->refunded_amount,
                $o->status === OrderStatus::Cancelled ? ($o->cancel_reason ?? '') : ($o->discount_reason ?? ''),
            ])->all();

        return [
            'columns' => [['Waktu', 'text'], ['Order', 'text'], ['Outlet', 'text'], ['Kasir', 'text'], ['Jenis', 'text'], ['Diskon', 'money'], ['Dikembalikan', 'money'], ['Alasan', 'text']],
            'rows' => $rows,
            'totals' => $this->totalsOf($rows, [5, 6], 'Total', 4),
        ];
    }

    private function inventory(): array
    {
        $rows = IngredientStock::whereIn('outlet_id', $this->outletIds)->with(['ingredient', 'outlet'])->get()
            ->sortBy(fn ($s) => $s->outlet->name.' '.$s->ingredient->name)
            ->map(fn ($s) => [
                $s->outlet->name,
                $s->ingredient->name,
                (float) $s->quantity,
                $s->ingredient->base_unit->value,
                (float) $s->ingredient->min_stock,
                (float) $s->quantity < 0 ? 'Minus' : ((float) $s->quantity <= (float) $s->ingredient->min_stock ? 'Menipis' : 'Aman'),
                (int) round((float) $s->avg_cost),
                $s->value(),
            ])->values()->all();

        return [
            'columns' => [['Outlet', 'text'], ['Bahan', 'text'], ['Stok', 'number'], ['Satuan', 'text'], ['Minimum', 'number'], ['Status', 'text'], ['HPP / satuan', 'money'], ['Nilai', 'money']],
            'rows' => $rows,
            'totals' => $this->totalsOf($rows, [7], 'Nilai persediaan saat ini', 6),
        ];
    }

    private function waste(): array
    {
        $rows = StockMovement::whereIn('outlet_id', $this->outletIds)
            ->whereIn('type', [StockMovementType::Waste->value, StockMovementType::Opname->value, StockMovementType::Adjustment->value])
            ->whereBetween('created_at', $this->period->utcRange())
            ->with(['ingredient', 'outlet', 'user'])
            ->latest('id')
            ->limit(500)
            ->get()
            ->map(fn ($m) => [
                $m->created_at->timezone($this->period->timezone)->format('d/m/Y H:i'),
                $m->outlet->name,
                $m->type->label(),
                $m->ingredient->name,
                (float) $m->quantity,
                $m->ingredient->base_unit->value,
                $m->value(),
                $m->note ?? '',
                $m->user?->name ?? 'Sistem',
            ])->all();

        return [
            'columns' => [['Waktu', 'text'], ['Outlet', 'text'], ['Jenis', 'text'], ['Bahan', 'text'], ['Jumlah', 'number'], ['Satuan', 'text'], ['Nilai', 'money'], ['Keterangan', 'text'], ['Oleh', 'text']],
            'rows' => $rows,
            'totals' => $this->totalsOf($rows, [6], 'Total nilai', 5),
        ];
    }

    private function purchasing(): array
    {
        $rows = PurchaseOrder::whereIn('outlet_id', $this->outletIds)
            ->where('status', '!=', 'cancelled')
            ->whereDate('order_date', '>=', $this->period->dateRange()[0])
            ->whereDate('order_date', '<=', $this->period->dateRange()[1])
            ->with('supplier')
            ->get()
            ->groupBy('supplier_id')
            ->map(fn ($pos) => [
                $pos->first()->supplier->name,
                $pos->count(),
                (int) $pos->sum('total'),
                (int) $pos->sum('received_total'),
                (int) $pos->sum('returned_total'),
                (int) $pos->sum('paid_amount'),
                (int) $pos->sum(fn ($po) => $po->outstanding()),
            ])->sortByDesc(2)->values()->all();

        return [
            'columns' => [['Supplier', 'text'], ['PO', 'number'], ['Nilai PO', 'money'], ['Diterima', 'money'], ['Retur', 'money'], ['Dibayar', 'money'], ['Sisa tagihan', 'money']],
            'rows' => $rows,
            'totals' => $this->totalsOf($rows, [1, 2, 3, 4, 5, 6], 'Total'),
        ];
    }

    private function profit(): array
    {
        $outlets = Outlet::whereIn('id', $this->outletIds)->orderBy('name')->get();

        $rows = $outlets->map(function (Outlet $outlet) {
            $s = (new FinanceMetrics($this->businessId, [$outlet->id], $this->period, includeShared: false))->summary();

            return [$outlet->name, $s['orders'], $s['net_sales'], $s['cogs'], $s['gross_profit'], $s['gross_margin'], $s['expenses'], $s['operating_profit']];
        })->all();

        $shared = (int) (new FinanceMetrics($this->businessId, [], $this->period))->expenses()->whereNull('outlet_id')->sum('amount');
        if ($shared > 0) {
            $rows[] = ['Biaya bersama', 0, 0, 0, 0, null, $shared, -$shared];
        }

        return [
            'columns' => [['Outlet', 'text'], ['Transaksi', 'number'], ['Penjualan bersih', 'money'], ['HPP', 'money'], ['Laba kotor', 'money'], ['Margin', 'percent'], ['Pengeluaran', 'money'], ['Laba operasional', 'money']],
            'rows' => $rows,
            'totals' => $this->totalsOf($rows, [1, 2, 3, 4, 6, 7], 'Total'),
        ];
    }

    /**
     * Baris total: kolom berindeks $sumIndexes dijumlahkan, label di kolom $labelIndex.
     *
     * @param  list<list<mixed>>  $rows
     * @param  list<int>  $sumIndexes
     * @return ?list<mixed>
     */
    private function totalsOf(array $rows, array $sumIndexes, string $label, int $labelIndex = 0): ?array
    {
        if ($rows === []) {
            return null;
        }

        $totals = array_fill(0, count($rows[0]), null);
        $totals[$labelIndex] = $label;

        foreach ($sumIndexes as $index) {
            $totals[$index] = array_sum(array_column($rows, $index));
        }

        return $totals;
    }
}
