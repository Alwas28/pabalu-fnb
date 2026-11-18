@php
    $columns = [
        'Antrean' => ['badge' => 'badge-gray', 'next' => 'Mulai Masak', 'items' => [
            ['menu' => 'Nasi Goreng Spesial', 'qty' => 2, 'order' => 'Meja 4', 'catatan' => 'Pedas level 3', 'menit' => 2],
            ['menu' => 'Mie Ayam Bakso', 'qty' => 1, 'order' => 'Takeaway #2842', 'catatan' => null, 'menit' => 3],
            ['menu' => 'Pisang Goreng Keju', 'qty' => 3, 'order' => 'Meja 9', 'catatan' => 'Keju ekstra', 'menit' => 5],
        ]],
        'Dimasak' => ['badge' => 'badge-amber', 'next' => 'Tandai Siap', 'items' => [
            ['menu' => 'Ayam Geprek', 'qty' => 2, 'order' => 'Meja 7', 'catatan' => 'Tanpa lalapan', 'menit' => 8],
            ['menu' => 'Kopi Susu Gula Aren', 'qty' => 4, 'order' => 'Delivery #2838', 'catatan' => 'Less sugar', 'menit' => 6],
        ]],
        'Siap Disajikan' => ['badge' => 'badge-green', 'next' => 'Sudah Disajikan', 'items' => [
            ['menu' => 'Es Teh Lemon', 'qty' => 2, 'order' => 'Meja 2', 'catatan' => null, 'menit' => 12],
        ]],
    ];
@endphp

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-fire-burner"></i>Antrean Dapur</div>
        <span class="chip">Status per item</span>
    </div>
    <div class="card-body grid gap-4 md:grid-cols-3" x-data>
        @foreach ($columns as $title => $col)
            <div class="rounded-2xl bg-pb-surface2 p-3 flex flex-col gap-2.5">
                <div class="flex items-center justify-between px-1">
                    <span class="text-[13px] font-semibold text-pb-text">{{ $title }}</span>
                    <span class="badge {{ $col['badge'] }}">{{ count($col['items']) }}</span>
                </div>
                @foreach ($col['items'] as $item)
                    <div class="card p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="text-[13px] font-semibold text-pb-text">{{ $item['qty'] }}× {{ $item['menu'] }}</div>
                            <span class="text-[11px] shrink-0 {{ $item['menit'] >= 10 ? 'trend-down font-semibold' : 'text-pb-muted' }}">{{ $item['menit'] }} mnt</span>
                        </div>
                        <div class="text-[11.5px] text-pb-muted mt-0.5">{{ $item['order'] }}</div>
                        @if ($item['catatan'])
                            <div class="text-[11.5px] mt-1.5 a-text"><i class="fa-solid fa-note-sticky mr-1"></i>{{ $item['catatan'] }}</div>
                        @endif
                        <button type="button" class="btn-ghost font-semibold w-full mt-2.5"
                                @click="Pabalu.toast('info', 'Pembaruan status dapur segera hadir')">{{ $col['next'] }}</button>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
