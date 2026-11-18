@php
    use App\Support\Reports\Period;

    // Penjualan bersih (definisi FinanceMetrics) untuk 7 hari, 6 bulan & 5 tahun terakhir.
    $now = $data->now();
    $week = collect($data->last7Days());
    $months = collect(range(5, 0))->map(fn ($i) => $now->startOfMonth()->subMonthsNoOverflow($i));
    $years = collect(range(4, 0))->map(fn ($i) => $now->startOfYear()->subYears($i));
    $net = fn ($from, $to) => $data->metrics(Period::make($from->toDateString(), $to->toDateString(), $data->timezone))->summary()['net_sales'];

    $chart = [
        'minggu' => [
            'labels' => $week->keys()->map(fn ($d) => \Carbon\Carbon::parse($d)->translatedFormat('D'))->values(),
            'data' => $week->pluck('net_sales')->values(),
        ],
        'bulan' => [
            'labels' => $months->map(fn ($m) => $m->translatedFormat('M')),
            'data' => $months->map(fn ($m) => $net($m, $m->endOfMonth())),
        ],
        'tahun' => [
            'labels' => $years->map(fn ($y) => $y->format('Y')),
            'data' => $years->map(fn ($y) => $net($y, $y->endOfYear())),
        ],
    ];
@endphp

<div class="card" x-data="salesChart(@js($chart), 'minggu')">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-chart-line"></i>Grafik Penjualan</div>
        <div class="flex items-center gap-2">
            @foreach (['minggu' => 'Minggu', 'bulan' => 'Bulan', 'tahun' => 'Tahun'] as $key => $label)
                <button type="button" class="btn-ghost font-semibold"
                        :class="{ active: period === '{{ $key }}' }"
                        @click="setPeriod('{{ $key }}')">{{ $label }}</button>
            @endforeach
        </div>
    </div>
    <div class="px-4 py-5">
        <div class="relative h-[260px]" wire:ignore>
            <canvas x-ref="canvas"></canvas>
        </div>
        <p class="text-[11px] text-pb-muted mt-2 px-1">Penjualan bersih (subtotal − diskon − refund), tanpa pajak &amp; service.</p>
    </div>
</div>
