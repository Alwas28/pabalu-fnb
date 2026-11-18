@php
    use App\Support\Money;

    $outlet = $data->outlet;
    $items = $data->menuAvailability();
@endphp

<div class="card overflow-hidden">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-utensils"></i>Ketersediaan Menu{{ $outlet ? ' · '.$outlet->name : '' }}</div>
        @can('menu.manage')
            <a href="{{ route('menu.items.index') }}" wire:navigate class="btn-ghost font-semibold">Kelola <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
        @endcan
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>Menu</th><th>Kategori</th><th class="text-right">Harga</th><th>Varian</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($items as $item)
                    @php $available = $outlet && $item->isAvailableAt($outlet); @endphp
                    <tr>
                        <td class="td-main">{{ $item->name }}</td>
                        <td>{{ $item->category?->name ?? '—' }}</td>
                        <td class="text-right whitespace-nowrap">{{ Money::format($outlet ? $item->priceAt($outlet) : $item->price) }}</td>
                        <td>{{ $item->variants->count() }}</td>
                        <td><span class="badge {{ $available ? 'badge-green' : 'badge-red' }}">{{ $available ? 'Tersedia' : 'Habis' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center !py-8">Belum ada menu aktif.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
