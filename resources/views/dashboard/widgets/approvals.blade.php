@php
    $approvals = [
        ['jenis' => 'Void', 'order' => '#ORD-2833', 'oleh' => 'Dedi (Kasir)', 'nilai' => 'Rp 25.000', 'alasan' => 'Salah input menu', 'badge' => 'badge-red'],
        ['jenis' => 'Diskon', 'order' => '#ORD-2839', 'oleh' => 'Dedi (Kasir)', 'nilai' => '20%', 'alasan' => 'Pelanggan member', 'badge' => 'badge-blue'],
        ['jenis' => 'Refund', 'order' => '#ORD-2830', 'oleh' => 'Sari (Kasir)', 'nilai' => 'Rp 19.000', 'alasan' => 'Minuman tumpah', 'badge' => 'badge-amber'],
    ];
@endphp

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-user-check"></i>Menunggu Persetujuan</div>
        <span class="badge badge-amber">{{ count($approvals) }} Permintaan</span>
    </div>
    <div class="py-2" x-data>
        @foreach ($approvals as $a)
            <div class="list-row items-start">
                <span class="badge {{ $a['badge'] }} shrink-0 mt-0.5">{{ $a['jenis'] }}</span>
                <div class="flex-1 min-w-0">
                    <div class="text-[13px] font-semibold text-pb-text">{{ $a['order'] }} · {{ $a['nilai'] }}</div>
                    <div class="text-[11.5px] text-pb-muted mt-0.5 truncate">{{ $a['alasan'] }} — {{ $a['oleh'] }}</div>
                </div>
                @can(\App\Enums\Permission::ApproveTransactions->value)
                    <div class="flex gap-1.5 shrink-0">
                        <button type="button" class="icon-btn !w-8 !h-8 !text-xs" style="color:#34d399" aria-label="Setujui"
                                @click="Pabalu.toast('info', 'Persetujuan transaksi segera hadir')"><i class="fa-solid fa-check"></i></button>
                        <button type="button" class="icon-btn !w-8 !h-8 !text-xs" style="color:#f87171" aria-label="Tolak"
                                @click="Pabalu.toast('info', 'Persetujuan transaksi segera hadir')"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                @endcan
            </div>
        @endforeach
    </div>
</div>
