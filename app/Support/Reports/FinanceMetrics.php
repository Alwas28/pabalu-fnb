<?php

namespace App\Support\Reports;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\OrderRefund;
use App\Models\PurchasePayment;
use App\Models\QuickPurchase;
use Illuminate\Database\Eloquent\Builder;

/**
 * Definisi metrik penjualan & keuangan (Blueprint Khusus F&B bagian 9 & 13) — satu sumber
 * untuk halaman Keuangan, Laporan & Dashboard agar angka konsisten.
 *
 * - Transaksi sah   : order tidak dibatalkan/void dan sudah dibayar (bukan open bill), tanggal = waktu order.
 * - Penjualan kotor : subtotal item (sebelum diskon, pajak & service charge).
 * - Penjualan bersih: penjualan kotor − diskon − porsi refund (refund dikurangi secara proporsional
 *                     sehingga pajak & service yang ikut dikembalikan tidak mengurangi penjualan).
 * - Pajak & service : dicatat terpisah, bukan pendapatan usaha.
 * - HPP / COGS      : estimasi biaya bahan dari resep saat transaksi (order_items.cost_amount).
 * - Laba kotor      : penjualan bersih − HPP.  Laba operasional: laba kotor − pengeluaran operasional.
 * - Arus kas        : uang diterima − refund − pembayaran supplier − belanja cepat − pengeluaran (berdasarkan tanggal bayar).
 *   Belanja cepat bukan pengeluaran operasional: biaya bahannya masuk laba lewat HPP resep.
 *   Penjualan bersih tidak sama dengan kas masuk (open bill, refund, pajak & pembayaran tempo berbeda waktu).
 */
class FinanceMetrics
{
    /**
     * @param  list<int>  $outletIds
     * @param  bool  $includeShared  sertakan pengeluaran tanpa outlet (biaya bersama)
     */
    public function __construct(
        private int $businessId,
        private array $outletIds,
        private Period $period,
        private bool $includeShared = true,
    ) {}

    /** Transaksi sah dalam periode. */
    public function sales(): Builder
    {
        return Order::query()
            ->where('business_id', $this->businessId)
            ->whereIn('outlet_id', $this->outletIds)
            ->whereBetween('placed_at', $this->period->utcRange())
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where('payment_status', '!=', PaymentStatus::Pending->value);
    }

    /**
     * @return array<string, int|float>
     */
    public function summary(): array
    {
        $row = $this->sales()->selectRaw('
            count(*) as orders,
            coalesce(sum(subtotal), 0) as gross,
            coalesce(sum(discount_amount), 0) as discount,
            coalesce(sum(service_charge_amount), 0) as service,
            coalesce(sum(tax_amount), 0) as tax,
            coalesce(sum(total), 0) as total,
            coalesce(sum(refunded_amount), 0) as refunds,
            coalesce(sum(case when total > 0 then refunded_amount * (subtotal - discount_amount) * 1.0 / total else 0 end), 0) as refund_net
        ')->first();

        $cogs = (int) OrderItem::whereIn('order_id', $this->sales()->select('id'))->sum('cost_amount');
        $netSales = (int) $row->gross - (int) $row->discount - (int) round((float) $row->refund_net);
        $expenses = $this->expenseTotal();
        $voids = $this->voids();

        return [
            'orders' => (int) $row->orders,
            'gross_sales' => (int) $row->gross,
            'discount' => (int) $row->discount,
            'refunds' => (int) $row->refunds,
            'net_sales' => $netSales,
            'service_charge' => (int) $row->service,
            'tax' => (int) $row->tax,
            'total_billed' => (int) $row->total,
            'average_order' => (int) $row->orders > 0 ? (int) round((int) $row->total / (int) $row->orders) : 0,
            'cogs' => $cogs,
            'gross_profit' => $netSales - $cogs,
            'gross_margin' => $netSales > 0 ? round(($netSales - $cogs) / $netSales * 100, 1) : 0.0,
            'expenses' => $expenses,
            'operating_profit' => $netSales - $cogs - $expenses,
            'void_count' => $voids['count'],
            'void_amount' => $voids['amount'],
        ];
    }

    /** Void (order lunas yang dibatalkan) dalam periode. */
    public function voids(): array
    {
        $row = Order::query()
            ->where('business_id', $this->businessId)
            ->whereIn('outlet_id', $this->outletIds)
            ->whereBetween('placed_at', $this->period->utcRange())
            ->where('status', OrderStatus::Cancelled->value)
            ->where('paid_amount', '>', 0)
            ->selectRaw('count(*) as c, coalesce(sum(paid_amount), 0) as v')
            ->first();

        return ['count' => (int) $row->c, 'amount' => (int) $row->v];
    }

    public function expenses(): Builder
    {
        return Expense::query()
            ->where('business_id', $this->businessId)
            ->whereDate('spent_at', '>=', $this->period->dateRange()[0])
            ->whereDate('spent_at', '<=', $this->period->dateRange()[1])
            ->where(fn ($q) => $q->whereIn('outlet_id', $this->outletIds)->when($this->includeShared, fn ($q) => $q->orWhereNull('outlet_id')));
    }

    public function expenseTotal(): int
    {
        return (int) $this->expenses()->sum('amount');
    }

    /**
     * Arus kas berdasarkan tanggal uang benar-benar diterima / dibayar.
     *
     * @return array<string, int>
     */
    public function cashFlow(): array
    {
        [$from, $to] = $this->period->utcRange();
        $orderScope = fn ($q) => $q->where('business_id', $this->businessId)->whereIn('outlet_id', $this->outletIds);

        $received = (int) OrderPayment::whereHas('order', $orderScope)->whereBetween('paid_at', [$from, $to])->sum('amount');
        $refunded = (int) OrderRefund::whereHas('order', $orderScope)->whereBetween('created_at', [$from, $to])->sum('amount');
        $supplier = (int) PurchasePayment::whereHas('purchaseOrder', $orderScope)->whereDate('paid_at', '>=', $this->period->dateRange()[0])->whereDate('paid_at', '<=', $this->period->dateRange()[1])->sum('amount');
        $quickPurchases = (int) QuickPurchase::where('business_id', $this->businessId)->whereIn('outlet_id', $this->outletIds)
            ->whereNull('cancelled_at')
            ->whereDate('purchased_at', '>=', $this->period->dateRange()[0])->whereDate('purchased_at', '<=', $this->period->dateRange()[1])
            ->sum('total');
        $expenses = $this->expenseTotal();

        return [
            'received' => $received,
            'refunded' => $refunded,
            'supplier_payments' => $supplier,
            'quick_purchases' => $quickPurchases,
            'expenses' => $expenses,
            'net' => $received - $refunded - $supplier - $quickPurchases - $expenses,
        ];
    }

    /**
     * Penerimaan bersih per metode pembayaran (diterima − refund) berdasarkan tanggal bayar.
     *
     * @return array<string, array{received: int, refunded: int, net: int}>
     */
    public function paymentsByMethod(): array
    {
        [$from, $to] = $this->period->utcRange();
        $orderScope = fn ($q) => $q->where('business_id', $this->businessId)->whereIn('outlet_id', $this->outletIds);

        $received = OrderPayment::whereHas('order', $orderScope)->whereBetween('paid_at', [$from, $to])
            ->selectRaw('method, sum(amount) as v')->groupBy('method')->pluck('v', 'method');
        $refunded = OrderRefund::whereHas('order', $orderScope)->whereBetween('created_at', [$from, $to])
            ->selectRaw('method, sum(amount) as v')->groupBy('method')->pluck('v', 'method');

        $methods = $received->keys()->merge($refunded->keys())->unique();

        return $methods->mapWithKeys(fn ($m) => [$m => [
            'received' => (int) ($received[$m] ?? 0),
            'refunded' => (int) ($refunded[$m] ?? 0),
            'net' => (int) ($received[$m] ?? 0) - (int) ($refunded[$m] ?? 0),
        ]])->sortByDesc('net')->all();
    }

    /**
     * Penjualan per tanggal lokal.
     *
     * @return array<string, array{orders: int, net_sales: int, total: int}>
     */
    public function daily(): array
    {
        $rows = $this->sales()->get(['placed_at', 'subtotal', 'discount_amount', 'total', 'refunded_amount']);
        $days = [];

        for ($day = $this->period->from; $day->lessThanOrEqualTo($this->period->to); $day = $day->addDay()) {
            $days[$day->toDateString()] = ['orders' => 0, 'net_sales' => 0, 'total' => 0];
        }

        foreach ($rows as $order) {
            $key = $order->placed_at->timezone($this->period->timezone)->toDateString();
            $refundShare = $order->total > 0 ? $order->refunded_amount * ($order->subtotal - $order->discount_amount) / $order->total : 0;
            $days[$key]['orders']++;
            $days[$key]['net_sales'] += (int) round($order->subtotal - $order->discount_amount - $refundShare);
            $days[$key]['total'] += $order->total;
        }

        return $days;
    }
}
