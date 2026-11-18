@php
    use App\Support\Money;

    $isKasir = $role === \App\Enums\UserRole::Kasir;
    $orders = $data->recentOrders();
@endphp

<div class="card overflow-hidden">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i>{{ $isKasir ? 'Order Shift Ini' : 'Order Terbaru' }}</div>
        @can('orders.view')
            <a href="{{ route('orders.index') }}" wire:navigate class="btn-ghost font-semibold">Lihat Semua <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
        @endcan
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>No. Order</th>
                    <th>Layanan</th>
                    <th>Item</th>
                    <th>Total</th>
                    <th>Pembayaran</th>
                    <th>Status</th>
                    @unless ($isKasir)
                        <th>Kasir</th>
                    @endunless
                    <th>Waktu</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td class="td-main">
                            @can('view', $order)
                                <a href="{{ route('orders.show', $order) }}" wire:navigate class="link">{{ $order->number }}</a>
                            @else
                                {{ $order->number }}
                            @endcan
                        </td>
                        <td>{{ $order->service_type->label() }}{{ $order->table_number ? ' · Meja '.$order->table_number : '' }}</td>
                        <td>{{ $order->items_count }}</td>
                        <td class="whitespace-nowrap">{{ Money::format($order->total) }}</td>
                        <td><span class="badge {{ $order->payment_status->badge() }}">{{ $order->payment_status->label() }}</span></td>
                        <td><span class="badge {{ $order->status->badge() }}">{{ $order->status->label() }}</span></td>
                        @unless ($isKasir)
                            <td>{{ $order->cashier ? strtok($order->cashier->name, ' ') : '—' }}</td>
                        @endunless
                        <td class="whitespace-nowrap">{{ $order->placed_at->locale('id')->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center !py-8">{{ $isKasir ? 'Belum ada order di shift ini.' : 'Belum ada order.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
