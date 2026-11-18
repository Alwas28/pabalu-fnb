@php
    use App\Enums\KitchenItemStatus;

    $outlet = $data->outlet;
    $counts = $data->kitchenCounts();
    $stages = $outlet ? array_values(array_filter($outlet->kitchenStages(), fn ($s) => $s !== KitchenItemStatus::Served)) : [];
    $next = ['queued' => 'Mulai Masak', 'preparing' => 'Tandai Siap', 'ready' => 'Sajikan'];
@endphp

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-fire-burner"></i>Antrean Dapur</div>
        <a href="{{ route('kitchen.index') }}" wire:navigate class="btn-ghost font-semibold">Buka layar dapur <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
    </div>
    @if (! $outlet?->usesKitchenDisplay())
        <div class="card-body text-[13px] text-pb-muted">{{ $outlet?->name }} memakai cetak tiket dapur — antrean tidak dilacak di layar.</div>
    @else
        <div class="card-body grid gap-4" style="grid-template-columns: repeat({{ max(1, count($stages)) }}, minmax(0, 1fr))">
            @foreach ($stages as $stage)
                @php $items = $data->kitchenItems($stage); @endphp
                <div class="rounded-2xl bg-pb-surface2 p-3 flex flex-col gap-2.5">
                    <div class="flex items-center justify-between px-1">
                        <span class="text-[13px] font-semibold text-pb-text">{{ $stage->label() }}</span>
                        <span class="badge {{ $stage->badge() }}">{{ $counts[$stage->value] ?? 0 }}</span>
                    </div>
                    @forelse ($items as $item)
                        @php $minutes = (int) $item->order->placed_at->diffInMinutes(now()); @endphp
                        <div class="card p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="text-[13px] font-semibold text-pb-text">{{ $item->quantity }}× {{ $item->displayName() }}</div>
                                <span class="text-[11px] shrink-0 {{ $minutes >= 10 ? 'trend-down font-semibold' : 'text-pb-muted' }}">{{ $minutes }} mnt</span>
                            </div>
                            <div class="text-[11.5px] text-pb-muted mt-0.5">{{ $item->order->table_number ? 'Meja '.$item->order->table_number : $item->order->service_type->label() }} · {{ $item->order->number }}</div>
                            @if ($item->notes)
                                <div class="text-[11.5px] mt-1.5 a-text"><i class="fa-solid fa-note-sticky mr-1"></i>{{ $item->notes }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="text-[12px] text-pb-muted text-center py-3">Kosong</div>
                    @endforelse
                    <a href="{{ route('kitchen.index', ['stage' => $stage->value]) }}" wire:navigate class="btn-ghost font-semibold w-full text-center">{{ $next[$stage->value] ?? 'Proses' }}</a>
                </div>
            @endforeach
        </div>
    @endif
</div>
