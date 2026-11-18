@php
    $topMenu = [
        ['nama' => 'Kopi Susu Gula Aren', 'kategori' => 'Minuman', 'terjual' => 312, 'persen' => 85, 'warna' => '#f59e0b'],
        ['nama' => 'Nasi Goreng Spesial', 'kategori' => 'Makanan', 'terjual' => 248, 'persen' => 67, 'warna' => '#818cf8'],
        ['nama' => 'Es Teh Lemon', 'kategori' => 'Minuman', 'terjual' => 201, 'persen' => 54, 'warna' => '#34d399'],
        ['nama' => 'Mie Ayam Bakso', 'kategori' => 'Makanan', 'terjual' => 189, 'persen' => 51, 'warna' => '#60a5fa'],
        ['nama' => 'Pisang Goreng Keju', 'kategori' => 'Camilan', 'terjual' => 143, 'persen' => 39, 'warna' => '#f87171'],
    ];
@endphp

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-trophy"></i>Menu Terlaris</div>
        <span class="chip">Bulan Ini</span>
    </div>
    <div class="py-2">
        @foreach ($topMenu as $i => $m)
            <div class="list-row">
                <div class="w-[26px] h-[26px] rounded-lg grid place-items-center text-[11px] font-bold shrink-0"
                     style="background:{{ $m['warna'] }}22;color:{{ $m['warna'] }}">{{ $i + 1 }}</div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-baseline justify-between gap-2">
                        <div class="text-[13px] font-semibold text-pb-text truncate">{{ $m['nama'] }}</div>
                        <span class="text-[10.5px] text-pb-muted shrink-0">{{ $m['kategori'] }}</span>
                    </div>
                    <div class="flex items-center gap-2 mt-1">
                        <div class="prog-bar flex-1"><div class="prog-fill" style="width:{{ $m['persen'] }}%;background:{{ $m['warna'] }}"></div></div>
                        <span class="text-[11px] text-pb-muted shrink-0">{{ $m['terjual'] }} porsi</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
