@php
    $outlets = [
        ['nama' => 'Outlet Kendari', 'omzet' => 'Rp 21,4 Jt', 'order' => 562, 'pct' => 100],
        ['nama' => 'Outlet Baubau', 'omzet' => 'Rp 15,9 Jt', 'order' => 418, 'pct' => 74],
        ['nama' => 'Outlet Kolaka', 'omzet' => 'Rp 11,4 Jt', 'order' => 304, 'pct' => 53],
    ];
@endphp

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-store"></i>Performa Outlet Bulan Ini</div>
        <span class="chip">{{ count($outlets) }} Outlet</span>
    </div>
    <div class="card-body grid gap-5 md:grid-cols-3">
        @foreach ($outlets as $o)
            <div>
                <div class="flex items-center justify-between gap-2">
                    <div class="text-[13px] font-semibold text-pb-text truncate">{{ $o['nama'] }}</div>
                    <div class="text-[13px] font-bold a-text shrink-0">{{ $o['omzet'] }}</div>
                </div>
                <div class="prog-bar mt-2"><div class="prog-fill" style="width:{{ $o['pct'] }}%"></div></div>
                <div class="text-[11.5px] text-pb-muted mt-1.5">{{ number_format($o['order'], 0, ',', '.') }} order</div>
            </div>
        @endforeach
    </div>
</div>
