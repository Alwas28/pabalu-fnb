@php
    use App\Support\Money;

    $top = $data->topMenu();
    $maxQty = max(1, (int) $top->max('qty'));
@endphp

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-trophy"></i>Menu Terlaris</div>
        @can('reports.view')
            <a href="{{ route('reports.index', ['report' => 'menu']) }}" wire:navigate class="btn-ghost font-semibold">Bulan ini <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
        @else
            <span class="chip">Bulan ini</span>
        @endcan
    </div>
    <div class="card-body flex flex-col gap-3.5">
        @forelse ($top as $i => $item)
            <div>
                <div class="flex items-center justify-between gap-2 text-[13px]">
                    <span class="font-semibold text-pb-text truncate"><span class="text-pb-muted mr-1.5">{{ $i + 1 }}.</span>{{ $item->name }}</span>
                    <span class="text-pb-sub whitespace-nowrap">{{ (int) $item->qty }} porsi · {{ Money::format((int) $item->revenue) }}</span>
                </div>
                <div class="prog-bar mt-1.5"><div class="prog-fill" style="width: {{ round($item->qty / $maxQty * 100) }}%"></div></div>
            </div>
        @empty
            <div class="text-[13px] text-pb-muted text-center py-6">Belum ada penjualan bulan ini.</div>
        @endforelse
    </div>
</div>
