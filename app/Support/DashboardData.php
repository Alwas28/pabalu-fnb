<?php

namespace App\Support;

use App\Enums\ApprovalStatus;
use App\Enums\KitchenItemStatus;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\CashShift;
use App\Models\IngredientStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\RefundRequest;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Pos\ShiftManager;
use App\Support\Reports\FinanceMetrics;
use App\Support\Reports\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Data dashboard per pengguna (Blueprint Khusus F&B bagian 9), dihitung dari transaksi nyata
 * dengan definisi yang sama dengan FinanceMetrics.
 *
 * Cakupan outlet: Owner & Business Admin melihat semua outlet; role lain melihat outlet aktifnya.
 */
class DashboardData
{
    public readonly Business $business;

    public readonly ?Outlet $outlet;

    public readonly UserRole $role;

    /** @var list<int> */
    public readonly array $outletIds;

    public readonly string $timezone;

    public function __construct(public readonly User $user)
    {
        $this->business = $user->activeBusiness();
        $this->outlet = $user->currentOutlet();
        $this->role = $user->currentRole();
        $this->timezone = $this->business->timezone ?: config('app.timezone');
        $this->outletIds = $this->role->coversAllOutlets()
            ? $user->accessibleOutlets()->pluck('id')->all()
            : array_filter([$this->outlet?->id]);
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    public function metrics(Period $period, ?array $outletIds = null): FinanceMetrics
    {
        return new FinanceMetrics($this->business->id, $outletIds ?? $this->outletIds, $period);
    }

    public function today(): Period
    {
        return Period::make($this->now()->toDateString(), $this->now()->toDateString(), $this->timezone);
    }

    public function monthToDate(): Period
    {
        return Period::make($this->now()->startOfMonth()->toDateString(), $this->now()->toDateString(), $this->timezone);
    }

    /** Periode pembanding yang sama panjang: hari kemarin atau bulan lalu s.d. tanggal yang sama. */
    public function previous(Period $period, bool $monthly): Period
    {
        $from = $monthly ? $period->from->subMonthNoOverflow() : $period->from->subDay();
        $to = $monthly ? $period->to->subMonthNoOverflow() : $period->to->subDay();

        return Period::make($from->toDateString(), $to->toDateString(), $this->timezone);
    }

    /** "+12,4% vs bulan lalu" beserta arah tren. */
    public static function trend(int|float $current, int|float $previous, string $suffix): array
    {
        if ($previous == 0) {
            return [$current > 0 ? 'Baru '.$suffix : 'Belum ada data '.$suffix, $current > 0 ? true : null];
        }

        $change = ($current - $previous) / abs($previous) * 100;

        return [($change >= 0 ? '+' : '').number_format($change, 1, ',', '.').'% '.$suffix, $change >= 0];
    }

    /** Ringkas: 48.700.000 → "Rp 48,7 Jt". */
    public static function compactMoney(int $amount): string
    {
        $abs = abs($amount);
        $sign = $amount < 0 ? '-' : '';

        return match (true) {
            $abs >= 1_000_000_000 => $sign.'Rp '.rtrim(rtrim(number_format($abs / 1_000_000_000, 1, ',', '.'), '0'), ',').' M',
            $abs >= 1_000_000 => $sign.'Rp '.rtrim(rtrim(number_format($abs / 1_000_000, 1, ',', '.'), '0'), ',').' Jt',
            default => Money::format($amount),
        };
    }

    /* ─────────────── Widget ─────────────── */

    /** Penjualan bersih 7 hari terakhir. */
    public function last7Days(): array
    {
        $period = Period::make($this->now()->subDays(6)->toDateString(), $this->now()->toDateString(), $this->timezone);

        return $this->metrics($period)->daily();
    }

    /** @return Collection<int, object{name: string, qty: int, revenue: int}> */
    public function topMenu(int $limit = 5): Collection
    {
        return OrderItem::query()
            ->whereIn('order_id', $this->metrics($this->monthToDate())->sales()->select('id'))
            ->selectRaw('name, sum(quantity) as qty, sum(line_total) as revenue')
            ->groupBy('name')
            ->orderByDesc('qty')
            ->limit($limit)
            ->get();
    }

    /** Order terbaru; kasir hanya order shift-nya sendiri. */
    public function recentOrders(int $limit = 6): Collection
    {
        $query = Order::whereIn('outlet_id', $this->outletIds)->with(['cashier'])->withCount('items')->latest('placed_at')->latest('id');

        if ($this->role === UserRole::Kasir) {
            $shift = $this->kasirShift();
            $query->where(fn ($q) => $q->where('cash_shift_id', $shift?->id ?? 0)
                ->orWhereHas('payments', fn ($p) => $p->where('cash_shift_id', $shift?->id ?? 0)));
        }

        return $query->limit($limit)->get();
    }

    public function kasirShift(): ?CashShift
    {
        return $this->outlet ? CashShift::currentFor($this->user, $this->outlet) : null;
    }

    public function shiftSummary(): ?array
    {
        $shift = $this->kasirShift();

        return $shift ? app(ShiftManager::class)->summary($shift) : null;
    }

    /** Bahan di bawah minimum / minus. */
    public function lowStock(int $limit = 6): Collection
    {
        return IngredientStock::whereIn('outlet_id', $this->outletIds)
            ->join('ingredients', 'ingredients.id', '=', 'ingredient_stocks.ingredient_id')
            ->where('ingredients.is_active', true)
            ->whereColumn('ingredient_stocks.quantity', '<=', 'ingredients.min_stock')
            ->select('ingredient_stocks.*')
            ->with(['ingredient', 'outlet'])
            ->orderByRaw('ingredient_stocks.quantity - ingredients.min_stock')
            ->limit($limit)
            ->get();
    }

    public function lowStockCount(): int
    {
        return IngredientStock::whereIn('outlet_id', $this->outletIds)
            ->join('ingredients', 'ingredients.id', '=', 'ingredient_stocks.ingredient_id')
            ->where('ingredients.is_active', true)
            ->whereColumn('ingredient_stocks.quantity', '<=', 'ingredients.min_stock')
            ->count();
    }

    /** Penjualan bulan ini per outlet. */
    public function outletPerformance(): Collection
    {
        $period = $this->monthToDate();

        return Outlet::whereIn('id', $this->outletIds)->orderBy('name')->get()->map(function (Outlet $outlet) use ($period) {
            $s = $this->metrics($period, [$outlet->id])->summary();

            return (object) ['outlet' => $outlet, 'net_sales' => $s['net_sales'], 'orders' => $s['orders'], 'gross_profit' => $s['gross_profit']];
        });
    }

    public function pendingApprovals(int $limit = 5): Collection
    {
        return RefundRequest::whereIn('outlet_id', $this->outletIds)->where('status', ApprovalStatus::Pending)
            ->with(['order', 'requester'])->oldest()->limit($limit)->get();
    }

    public function pendingApprovalCount(): int
    {
        return RefundRequest::whereIn('outlet_id', $this->outletIds)->where('status', ApprovalStatus::Pending)->count();
    }

    /** Jumlah porsi per tahap dapur di outlet aktif. */
    public function kitchenCounts(): array
    {
        return OrderItem::whereHas('order', fn ($q) => $q->whereIn('outlet_id', $this->outletIds)->inKitchen())
            ->selectRaw('kitchen_status, sum(quantity) as qty')
            ->groupBy('kitchen_status')
            ->pluck('qty', 'kitchen_status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** Item dapur aktif per tahap (untuk widget antrean). */
    public function kitchenItems(KitchenItemStatus $status, int $limit = 4): Collection
    {
        return OrderItem::whereHas('order', fn ($q) => $q->whereIn('outlet_id', $this->outletIds)->inKitchen())
            ->where('kitchen_status', $status->value)
            ->with('order')
            ->oldest('id')
            ->limit($limit)
            ->get();
    }

    /** Rata-rata menit dari order masuk sampai item tersaji, hari ini. */
    public function averageServeMinutes(): ?int
    {
        [$from, $to] = $this->today()->utcRange();

        $items = OrderItem::whereHas('order', fn ($q) => $q->whereIn('outlet_id', $this->outletIds)->whereBetween('placed_at', [$from, $to]))
            ->where('kitchen_status', KitchenItemStatus::Served->value)
            ->whereNotNull('kitchen_updated_at')
            ->with('order:id,placed_at')
            ->get(['id', 'order_id', 'kitchen_updated_at']);

        if ($items->isEmpty()) {
            return null;
        }

        return (int) round($items->avg(fn ($i) => $i->order->placed_at->diffInMinutes($i->kitchen_updated_at)));
    }

    public function waitingPurchaseOrders(int $limit = 5): Collection
    {
        return PurchaseOrder::whereIn('outlet_id', $this->outletIds)
            ->whereIn('status', [PurchaseStatus::Ordered->value, PurchaseStatus::Partial->value])
            ->with('supplier')->orderBy('expected_date')->limit($limit)->get();
    }

    public function waitingPurchaseOrderCount(): int
    {
        return PurchaseOrder::whereIn('outlet_id', $this->outletIds)->whereIn('status', [PurchaseStatus::Ordered->value, PurchaseStatus::Partial->value])->count();
    }

    public function wasteValueThisWeek(): int
    {
        return (int) StockMovement::whereIn('outlet_id', $this->outletIds)
            ->where('type', StockMovementType::Waste->value)
            ->where('created_at', '>=', $this->now()->startOfWeek()->utc())
            ->get(['quantity', 'unit_cost'])
            ->sum(fn ($m) => abs($m->value()));
    }

    public function lastStockCount(): ?StockCount
    {
        return StockCount::whereIn('outlet_id', $this->outletIds)->where('status', StockCount::COMPLETED)->latest('completed_at')->first();
    }

    /** Menu di outlet aktif: yang habis lebih dulu. */
    public function menuAvailability(int $limit = 6): Collection
    {
        $outlet = $this->outlet;

        return $this->business->menuItems()->where('is_active', true)
            ->with(['category', 'variants', 'outlets' => fn ($q) => $q->whereKey($outlet?->id ?? 0)])
            ->orderBy('name')->get()
            ->sortBy(fn ($m) => $outlet && $m->isAvailableAt($outlet) ? 1 : 0)
            ->take($limit)->values();
    }
}
