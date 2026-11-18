<?php

use App\Enums\KitchenItemStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Support\Pos\KitchenFlow;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Antrean Dapur')] class extends Component
{
    /** Filter tiket: semua, atau tahap item tertentu. */
    #[Url]
    public string $stage = '';

    #[Computed]
    public function outlet(): ?Outlet
    {
        return auth()->user()->currentOutlet();
    }

    /** Tiket aktif (order dengan item yang belum tersaji), terlama dulu. */
    #[Computed]
    public function tickets()
    {
        if (! $this->outlet?->usesKitchenDisplay()) {
            return collect();
        }

        return Order::where('outlet_id', $this->outlet->id)
            ->inKitchen()
            ->with(['items' => fn ($q) => $q->with('modifiers')->orderBy('kitchen_batch')->orderBy('id')])
            ->oldest('placed_at')
            ->get()
            ->when($this->stage !== '', fn ($tickets) => $tickets->filter(
                fn (Order $o) => $o->items->contains(fn ($i) => $i->kitchen_status->value === $this->stage),
            ))
            ->values();
    }

    /** Jumlah item per tahap untuk chip filter. */
    #[Computed]
    public function counts(): array
    {
        if (! $this->outlet?->usesKitchenDisplay()) {
            return [];
        }

        return OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('outlet_id', $this->outlet->id)->inKitchen())
            ->selectRaw('kitchen_status, sum(quantity) as qty')
            ->groupBy('kitchen_status')
            ->pluck('qty', 'kitchen_status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function kitchenOutlet(): Outlet
    {
        $outlet = $this->outlet;
        abort_unless($outlet && auth()->user()->canAccessOutlet($outlet), 403);

        return $outlet;
    }

    public function advanceItem(int $itemId): void
    {
        $outlet = $this->kitchenOutlet();
        $item = OrderItem::whereHas('order', fn ($q) => $q->where('outlet_id', $outlet->id))->find($itemId);
        abort_unless($item, 404);

        app(KitchenFlow::class)->advanceItem(auth()->user(), $item);
        unset($this->tickets, $this->counts);
    }

    public function advanceOrder(int $orderId): void
    {
        $outlet = $this->kitchenOutlet();
        $order = Order::where('outlet_id', $outlet->id)->find($orderId);
        abort_unless($order, 404);

        app(KitchenFlow::class)->advanceOrder(auth()->user(), $order);
        unset($this->tickets, $this->counts);
    }
}; ?>

<div class="flex flex-col gap-5" wire:poll.10s>
    @php $outlet = $this->outlet; @endphp

    @if (! $outlet)
        <div class="card"><div class="card-body text-center text-pb-muted">Belum ada outlet yang ditugaskan.</div></div>
    @elseif (! $outlet->usesKitchenDisplay())
        <div class="card max-w-xl animate-fadeUp">
            <div class="card-body text-center py-10">
                <i class="fa-solid fa-print text-3xl text-pb-muted mb-3"></i>
                <div class="font-display font-bold text-pb-text mb-1">{{ $outlet->name }} memakai cetak tiket dapur</div>
                <p class="text-[13px] text-pb-muted">Pesanan dikirim ke dapur sebagai tiket cetak dari kasir. Ganti ke "Layar Dapur" di pengaturan outlet untuk memakai antrean ini.</p>
                @can('update', $outlet)
                    <a href="{{ route('settings.outlets.edit', $outlet) }}" wire:navigate class="btn btn-secondary mt-4"><i class="fa-solid fa-gear"></i>Pengaturan Outlet</a>
                @endcan
            </div>
        </div>
    @else
        @php $stages = $outlet->kitchenStages(); @endphp
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="$set('stage', '')" @class(['chip cursor-pointer', 'a-bg-lt a-text a-border' => $stage === ''])>Semua tiket ({{ $this->tickets->count() }})</button>
            @foreach ($stages as $s)
                @continue($s === KitchenItemStatus::Served)
                <button type="button" wire:click="$set('stage', '{{ $s->value }}')" @class(['chip cursor-pointer', 'a-bg-lt a-text a-border' => $stage === $s->value])>
                    {{ $s->label() }} · {{ $this->counts[$s->value] ?? 0 }}
                </button>
            @endforeach
            <span class="text-[12px] text-pb-muted ml-auto"><i class="fa-solid fa-rotate mr-1"></i>Diperbarui otomatis · tahap: {{ collect($stages)->map->label()->join(' → ') }}</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 items-start">
            @forelse ($this->tickets as $order)
                @php
                    $minutes = (int) $order->placed_at->diffInMinutes(now());
                    $activeItems = $order->items->filter(fn ($i) => $i->kitchen_status->isActive());
                    $lowest = $activeItems->map(fn ($i) => array_search($i->kitchen_status, $stages, true))->min();
                    $nextForTicket = $lowest !== null ? ($stages[$lowest + 1] ?? null) : null;
                @endphp
                <div wire:key="ticket-{{ $order->id }}" @class(['card overflow-hidden', '!border-[#f87171]' => $minutes >= 20])>
                    <div class="card-header !py-3">
                        <div class="min-w-0">
                            <div class="font-display text-base font-bold text-pb-text">
                                {{ $order->table_number ? 'Meja '.$order->table_number : $order->service_type->label() }}
                            </div>
                            <div class="text-[11.5px] text-pb-muted truncate">{{ $order->number }}{{ $order->customer_name ? ' · '.$order->customer_name : '' }}</div>
                        </div>
                        <span @class(['badge', 'badge-red' => $minutes >= 20, 'badge-amber' => $minutes >= 10 && $minutes < 20, 'badge-gray' => $minutes < 10])>
                            <i class="fa-regular fa-clock"></i>{{ $minutes }} mnt
                        </span>
                    </div>
                    <div class="flex flex-col divide-y divide-pb-border">
                        @foreach ($order->items as $item)
                            @php $next = $item->kitchen_status->isActive() ? KitchenFlow::next($outlet, $item->kitchen_status) : null; @endphp
                            <div wire:key="ki-{{ $item->id }}" @class(['px-4 py-2.5 flex items-start gap-3', 'opacity-45' => ! $item->kitchen_status->isActive()])>
                                <div class="font-display text-lg font-bold a-text w-7 shrink-0">{{ $item->quantity }}×</div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-[13.5px] font-semibold text-pb-text">{{ $item->displayName() }}
                                        @if ($item->kitchen_batch > 1)<span class="chip !text-[10px] !py-0 ml-1">Tambahan</span>@endif
                                    </div>
                                    @if ($item->modifiers->isNotEmpty())
                                        <div class="text-[12px] text-pb-sub">+ {{ $item->modifiers->pluck('name')->join(', ') }}</div>
                                    @endif
                                    @if ($item->notes)
                                        <div class="text-[12px] trend-down font-semibold">“{{ $item->notes }}”</div>
                                    @endif
                                </div>
                                @if ($next)
                                    <button type="button" wire:click="advanceItem({{ $item->id }})" wire:loading.attr="disabled" class="btn-ghost text-[11.5px] font-semibold !px-2 shrink-0">
                                        <span class="badge {{ $item->kitchen_status->badge() }} mr-1">{{ $item->kitchen_status->label() }}</span>→ {{ $next->actionLabel() }}
                                    </button>
                                @else
                                    <span class="badge {{ $item->kitchen_status->badge() }} shrink-0">{{ $item->kitchen_status->label() }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($nextForTicket)
                        <div class="px-4 py-3 border-t border-pb-border">
                            <button type="button" wire:click="advanceOrder({{ $order->id }})" wire:loading.attr="disabled" class="btn btn-primary w-full !py-2">
                                <i class="fa-solid fa-forward"></i>Semua → {{ $nextForTicket->actionLabel() }}
                            </button>
                        </div>
                    @endif
                </div>
            @empty
                <div class="card sm:col-span-2 xl:col-span-3 2xl:col-span-4">
                    <div class="card-body text-center py-10 text-pb-muted"><i class="fa-solid fa-mug-hot text-2xl mb-2 block"></i>Tidak ada pesanan di dapur.</div>
                </div>
            @endforelse
        </div>
    @endif
</div>
