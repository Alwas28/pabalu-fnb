@php
    $stokKritis = [
        ['nama' => 'Susu Full Cream', 'stok' => '2 L', 'min' => '10 L', 'warna' => '#f87171'],
        ['nama' => 'Gula Aren Cair', 'stok' => '0,8 L', 'min' => '3 L', 'warna' => '#f87171'],
        ['nama' => 'Biji Kopi Arabika', 'stok' => '0,5 kg', 'min' => '2 kg', 'warna' => '#f87171'],
        ['nama' => 'Telur Ayam', 'stok' => '18 btr', 'min' => '30 btr', 'warna' => '#fbbf24'],
        ['nama' => 'Keju Cheddar', 'stok' => '400 g', 'min' => '600 g', 'warna' => '#fbbf24'],
    ];
@endphp

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-triangle-exclamation" style="color:#f87171"></i>Stok Bahan Kritis</div>
        <span class="badge badge-red">{{ count($stokKritis) }} Bahan</span>
    </div>
    <div class="py-2" x-data>
        @foreach ($stokKritis as $s)
            <div class="list-row">
                <div class="w-2 h-2 rounded-full shrink-0" style="background:{{ $s['warna'] }}"></div>
                <div class="flex-1 min-w-0">
                    <div class="text-[13px] font-semibold text-pb-text truncate">{{ $s['nama'] }}</div>
                    <div class="text-[11.5px] text-pb-muted mt-0.5">
                        Stok: <span class="font-bold" style="color:{{ $s['warna'] }}">{{ $s['stok'] }}</span> / Min: {{ $s['min'] }}
                    </div>
                </div>
                @can(\App\Enums\Permission::ManagePurchasing->value)
                    <button type="button"
                            class="px-[11px] py-[5px] rounded-lg text-[11.5px] font-semibold"
                            style="border:1px solid {{ $s['warna'] }}44;background:{{ $s['warna'] }}15;color:{{ $s['warna'] }}"
                            @click="Pabalu.toast('info', @js('Buat PO untuk '.$s['nama'].' segera hadir'))">
                        Restock
                    </button>
                @endcan
            </div>
        @endforeach
    </div>
</div>
