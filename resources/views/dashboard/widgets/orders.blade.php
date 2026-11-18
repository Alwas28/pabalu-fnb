@php
    $isKasir = $role === \App\Enums\UserRole::Kasir;
    $orders = [
        ['id' => '#ORD-2841', 'layanan' => 'Dine-in · Meja 4', 'item' => 3, 'total' => 'Rp 87.000', 'bayar' => 'Lunas', 'status' => 'Selesai', 'kasir' => 'Dedi', 'waktu' => '10 mnt lalu'],
        ['id' => '#ORD-2840', 'layanan' => 'Takeaway', 'item' => 2, 'total' => 'Rp 46.000', 'bayar' => 'Lunas', 'status' => 'Siap', 'kasir' => 'Sari', 'waktu' => '18 mnt lalu'],
        ['id' => '#ORD-2839', 'layanan' => 'Dine-in · Meja 7', 'item' => 5, 'total' => 'Rp 162.000', 'bayar' => 'Pending', 'status' => 'Diproses', 'kasir' => 'Dedi', 'waktu' => '25 mnt lalu'],
        ['id' => '#ORD-2838', 'layanan' => 'Delivery', 'item' => 4, 'total' => 'Rp 118.000', 'bayar' => 'Lunas', 'status' => 'Selesai', 'kasir' => 'Sari', 'waktu' => '1 jam lalu'],
        ['id' => '#ORD-2837', 'layanan' => 'Takeaway', 'item' => 1, 'total' => 'Rp 22.000', 'bayar' => 'Refund', 'status' => 'Batal', 'kasir' => 'Dedi', 'waktu' => '2 jam lalu'],
        ['id' => '#ORD-2836', 'layanan' => 'Dine-in · Meja 2', 'item' => 6, 'total' => 'Rp 214.000', 'bayar' => 'Sebagian', 'status' => 'Disajikan', 'kasir' => 'Dedi', 'waktu' => '2 jam lalu'],
    ];

    // Kasir hanya melihat order dari shift-nya sendiri.
    if ($isKasir) {
        $orders = array_values(array_filter($orders, fn (array $o) => $o['kasir'] === 'Dedi'));
    }

    $bayarBadge = ['Lunas' => 'badge-green', 'Pending' => 'badge-amber', 'Sebagian' => 'badge-blue', 'Refund' => 'badge-red'];
    $statusBadge = ['Selesai' => 'badge-green', 'Siap' => 'badge-blue', 'Diproses' => 'badge-amber', 'Disajikan' => 'badge-gray', 'Batal' => 'badge-red'];
@endphp

<div class="card overflow-hidden">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i>{{ $isKasir ? 'Order Shift Ini' : 'Order Terbaru' }}</div>
        <button type="button" class="btn-ghost font-semibold" x-data
                @click="Pabalu.toast('info', 'Modul Pesanan segera hadir')">
            Lihat Semua <i class="fa-solid fa-arrow-right text-[10px]"></i>
        </button>
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
                @foreach ($orders as $o)
                    <tr>
                        <td><code class="text-xs font-bold a-text font-mono">{{ $o['id'] }}</code></td>
                        <td class="td-main">{{ $o['layanan'] }}</td>
                        <td>{{ $o['item'] }} item</td>
                        <td class="font-semibold" style="color:var(--text)">{{ $o['total'] }}</td>
                        <td><span class="badge {{ $bayarBadge[$o['bayar']] ?? 'badge-gray' }}">{{ $o['bayar'] }}</span></td>
                        <td><span class="badge {{ $statusBadge[$o['status']] ?? 'badge-gray' }}">{{ $o['status'] }}</span></td>
                        @unless ($isKasir)
                            <td>{{ $o['kasir'] }}</td>
                        @endunless
                        <td class="text-xs">{{ $o['waktu'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
