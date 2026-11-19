<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Support\Reports\FinanceMetrics;
use App\Support\Reports\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ringkasan penjualan outlet untuk dashboard aplikasi kasir. Angka mengikuti definisi FinanceMetrics
 * (sama dengan halaman Keuangan & Laporan web). Biaya bahan & laba sengaja tidak dikirim.
 */
class SummaryController extends Controller
{
    private const MAX_DAYS = 31;

    public function __invoke(Request $request, Outlet $outlet): JsonResponse
    {
        Gate::authorize('usePos', $outlet);

        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
            'scope' => ['nullable', 'in:outlet,me'],
        ]);

        // scope=me: hanya pesanan yang dibuat kasir yang sedang login.
        $cashierId = $request->input('scope') === 'me' ? $request->user()->id : null;

        $timezone = $outlet->business->timezone ?: config('app.timezone');
        $today = now($timezone)->toDateString();
        $from = $request->input('from') ?? $request->input('date') ?? $today;
        $to = $request->input('to') ?? $request->input('date') ?? $today;
        $period = Period::make($from, $to, $timezone);

        if ($period->from->diffInDays($period->to) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Rentang tanggal maksimal '.self::MAX_DAYS.' hari.']);
        }

        $metrics = new FinanceMetrics($outlet->business_id, [$outlet->id], $period, includeShared: false, cashierId: $cashierId);
        $summary = $metrics->summary();
        $singleDay = $period->from->isSameDay($period->to);

        return response()->json(['data' => [
            'period' => ['from' => $period->from->toDateString(), 'to' => $period->to->toDateString(), 'timezone' => $timezone],
            'scope' => $cashierId ? 'me' : 'outlet',
            'summary' => [
                'orders' => $summary['orders'],
                'gross_sales' => $summary['gross_sales'],
                'discount' => $summary['discount'],
                'refunds' => $summary['refunds'],
                'net_sales' => $summary['net_sales'],
                'service_charge' => $summary['service_charge'],
                'tax' => $summary['tax'],
                'total_billed' => $summary['total_billed'],
                'average_order' => $summary['average_order'],
                'void_count' => $summary['void_count'],
                'void_amount' => $summary['void_amount'],
            ],
            'payments_by_method' => collect($metrics->paymentsByMethod())
                ->map(fn (array $row, string $method) => ['method' => $method] + $row)->values(),
            // Satu hari: per jam (0–23). Rentang: per tanggal.
            'series' => $singleDay ? $this->hourly($metrics, $timezone) : collect($metrics->daily())
                ->map(fn (array $row, string $date) => ['label' => $date] + $row)->values(),
            'series_unit' => $singleDay ? 'hour' : 'day',
            'top_items' => OrderItem::query()
                ->whereIn('order_id', $metrics->sales()->select('id'))
                ->selectRaw('name, sum(quantity - voided_quantity) as quantity, sum(line_total) as revenue')
                ->groupBy('name')
                ->orderByDesc('quantity')
                ->limit(5)
                ->get()
                ->map(fn ($row) => ['name' => $row->name, 'quantity' => (int) $row->quantity, 'revenue' => (int) $row->revenue]),
            'open_bills' => [
                'count' => $outlet->orders()->open()->when($cashierId, fn ($q) => $q->where('cashier_id', $cashierId))->count(),
                'total' => (int) $outlet->orders()->open()->when($cashierId, fn ($q) => $q->where('cashier_id', $cashierId))->sum('total'),
            ],
        ]]);
    }

    /** @return list<array{label: string, orders: int, total: int}> */
    private function hourly(FinanceMetrics $metrics, string $timezone): array
    {
        $hours = array_fill(0, 24, ['orders' => 0, 'total' => 0]);

        foreach ($metrics->sales()->get(['placed_at', 'total']) as $order) {
            $hour = (int) $order->placed_at->timezone($timezone)->format('G');
            $hours[$hour]['orders']++;
            $hours[$hour]['total'] += $order->total;
        }

        return array_map(fn (int $hour, array $row) => ['label' => sprintf('%02d:00', $hour)] + $row, array_keys($hours), $hours);
    }
}
