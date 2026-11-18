@php
    $chart = [
        'minggu' => ['labels' => ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'], 'data' => [5200000, 7800000, 4100000, 8400000, 6900000, 9200000, 7100000]],
        'bulan' => ['labels' => ['Jul', 'Agu', 'Sep', 'Okt', 'Nov'], 'data' => [38500000, 42100000, 39800000, 45200000, 48700000]],
        'tahun' => ['labels' => ['2022', '2023', '2024', '2025', '2026'], 'data' => [320000000, 415000000, 398000000, 520000000, 470000000]],
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
    </div>
</div>
