@php
    $pos = [
        ['no' => 'PO-0127', 'supplier' => 'CV Susu Segar Sultra', 'item' => 3, 'jadwal' => 'Hari ini', 'status' => 'Dikirim', 'badge' => 'badge-blue'],
        ['no' => 'PO-0126', 'supplier' => 'Toko Bahan Kue Makmur', 'item' => 5, 'jadwal' => '20 Nov', 'status' => 'Dipesan', 'badge' => 'badge-amber'],
        ['no' => 'PO-0125', 'supplier' => 'Kopi Tolaki Roastery', 'item' => 2, 'jadwal' => '21 Nov', 'status' => 'Dipesan', 'badge' => 'badge-amber'],
        ['no' => 'PO-0124', 'supplier' => 'UD Telur Berkah', 'item' => 1, 'jadwal' => '17 Nov', 'status' => 'Diterima', 'badge' => 'badge-green'],
    ];
@endphp

<div class="card overflow-hidden">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-truck"></i>Purchase Order</div>
        <button type="button" class="btn-ghost font-semibold" x-data
                @click="Pabalu.toast('info', 'Modul Pembelian segera hadir')">
            Lihat Semua <i class="fa-solid fa-arrow-right text-[10px]"></i>
        </button>
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>No. PO</th><th>Supplier</th><th>Item</th><th>Jadwal</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($pos as $po)
                    <tr>
                        <td><code class="text-xs font-bold a-text font-mono">{{ $po['no'] }}</code></td>
                        <td class="td-main">{{ $po['supplier'] }}</td>
                        <td>{{ $po['item'] }} bahan</td>
                        <td>{{ $po['jadwal'] }}</td>
                        <td><span class="badge {{ $po['badge'] }}">{{ $po['status'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
