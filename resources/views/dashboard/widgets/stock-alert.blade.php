@php
    use App\Models\Ingredient;

    $rows = $data->lowStock();
    $showOutlet = count($data->outletIds) > 1;
@endphp

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-triangle-exclamation" style="color:#f87171"></i>Stok Bahan Kritis</div>
        @can('purchasing.manage')
            <a href="{{ route('purchasing.create') }}" wire:navigate class="btn-ghost font-semibold"><i class="fa-solid fa-cart-plus mr-1"></i>Restock</a>
        @endcan
    </div>
    <div class="py-2">
        @forelse ($rows as $stock)
            @php $minus = (float) $stock->quantity < 0; @endphp
            <div class="list-row">
                <div class="w-8 h-8 rounded-lg grid place-items-center shrink-0 text-xs" style="background:rgba(248,113,113,.14);color:#f87171">
                    <i class="fa-solid fa-box-open"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-[13px] font-semibold text-pb-text truncate">{{ $stock->ingredient->name }}</div>
                    <div class="text-[11px] text-pb-muted">
                        Minimum {{ Ingredient::qty($stock->ingredient->min_stock) }} {{ $stock->ingredient->base_unit->value }}{{ $showOutlet ? ' · '.$stock->outlet->name : '' }}
                    </div>
                </div>
                <span @class(['text-[13px] font-bold whitespace-nowrap', 'trend-down' => $minus, 'text-pb-text' => ! $minus])>
                    {{ Ingredient::qty($stock->quantity) }} {{ $stock->ingredient->base_unit->value }}
                </span>
            </div>
        @empty
            <div class="px-5 py-6 text-center text-[13px] text-pb-muted"><i class="fa-solid fa-circle-check mr-1" style="color:#34d399"></i>Semua stok bahan aman.</div>
        @endforelse
    </div>
    @can('inventory.manage')
        <div class="px-5 pb-4"><a href="{{ route('inventory.index') }}" wire:navigate class="link text-[12.5px]">Lihat stok bahan <i class="fa-solid fa-arrow-right text-[10px]"></i></a></div>
    @endcan
</div>
