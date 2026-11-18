@php
    use App\Support\Money;

    $orders = $data->waitingPurchaseOrders();
@endphp

<div class="card overflow-hidden">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-truck"></i>Purchase Order</div>
        <a href="{{ route('purchasing.index') }}" wire:navigate class="btn-ghost font-semibold">Lihat Semua <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>No. PO</th><th>Supplier</th><th>Perkiraan tiba</th><th class="text-right">Nilai</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse ($orders as $po)
                    <tr>
                        <td class="td-main"><a href="{{ route('purchasing.show', $po) }}" wire:navigate class="link">{{ $po->number }}</a></td>
                        <td>{{ $po->supplier->name }}</td>
                        <td>{{ $po->expected_date?->format('d/m/Y') ?? '—' }}</td>
                        <td class="text-right whitespace-nowrap">{{ Money::format($po->total) }}</td>
                        <td><span class="badge {{ $po->status->badge() }}">{{ $po->status->label() }}</span></td>
                        <td class="text-right"><a href="{{ route('purchasing.show', $po) }}" wire:navigate class="btn-ghost font-semibold">Terima Barang</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center !py-8">Tidak ada PO yang menunggu diterima.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
