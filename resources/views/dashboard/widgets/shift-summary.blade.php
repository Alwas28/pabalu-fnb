@php
    $payments = [
        ['metode' => 'Tunai', 'jumlah' => 'Rp 1.100.000', 'pct' => 58, 'warna' => '#34d399'],
        ['metode' => 'QRIS', 'jumlah' => 'Rp 620.000', 'pct' => 33, 'warna' => '#818cf8'],
        ['metode' => 'Transfer', 'jumlah' => 'Rp 180.000', 'pct' => 9, 'warna' => '#60a5fa'],
    ];
@endphp

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-cash-register"></i>Ringkasan Shift</div>
        <span class="badge badge-green"><i class="fa-solid fa-circle text-[6px]"></i>Buka</span>
    </div>
    <div class="card-body flex flex-col gap-4">
        <div class="grid grid-cols-2 gap-3 text-[12px]">
            <div class="rounded-xl bg-pb-surface2 p-3">
                <div class="text-pb-muted">Dibuka</div>
                <div class="text-pb-text font-semibold mt-0.5">08:00 · Kasir 1</div>
            </div>
            <div class="rounded-xl bg-pb-surface2 p-3">
                <div class="text-pb-muted">Modal awal</div>
                <div class="text-pb-text font-semibold mt-0.5">Rp 300.000</div>
            </div>
        </div>

        <div>
            <div class="tp-section-label !px-0">Pembayaran per metode</div>
            @foreach ($payments as $p)
                <div class="mt-2.5">
                    <div class="flex justify-between text-[12.5px]">
                        <span class="text-pb-sub">{{ $p['metode'] }}</span>
                        <span class="text-pb-text font-semibold">{{ $p['jumlah'] }}</span>
                    </div>
                    <div class="prog-bar mt-1.5"><div class="prog-fill" style="width:{{ $p['pct'] }}%;background:{{ $p['warna'] }}"></div></div>
                </div>
            @endforeach
        </div>

        <button type="button" class="btn btn-secondary w-full" x-data
                @click="Pabalu.toast('info', 'Tutup shift segera hadir')">
            <i class="fa-solid fa-lock"></i>Tutup Shift
        </button>
    </div>
</div>
