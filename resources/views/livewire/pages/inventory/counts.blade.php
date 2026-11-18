<?php

use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\StockCount;
use App\Support\Inventory\StockOperations;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Stok Opname')] class extends Component
{
    /** Opname yang sedang dibuka (draft untuk diisi, atau selesai untuk dilihat). */
    #[Url(as: 'id')]
    public ?int $countId = null;

    public string $note = '';

    /** @var array<int|string, string> stock_count_item_id → jumlah fisik */
    public array $counted = [];

    public function mount(): void
    {
        if ($this->countId) {
            $this->open($this->countId);
        }
    }

    #[Computed]
    public function outlet(): ?Outlet
    {
        return auth()->user()->currentOutlet();
    }

    #[Computed]
    public function counts()
    {
        return StockCount::where('outlet_id', $this->outlet?->id ?? 0)
            ->with(['user', 'completer'])
            ->withCount(['items as counted_items' => fn ($q) => $q->whereNotNull('counted_quantity')])
            ->latest('id')
            ->limit(30)
            ->get();
    }

    #[Computed]
    public function current(): ?StockCount
    {
        return $this->countId
            ? StockCount::where('outlet_id', $this->outlet?->id ?? 0)->with('items.ingredient')->find($this->countId)
            : null;
    }

    private function countOutlet(): Outlet
    {
        $outlet = $this->outlet;
        abort_unless($outlet && auth()->user()->canAccessOutlet($outlet), 403);

        return $outlet;
    }

    public function start(): void
    {
        $count = app(StockOperations::class)->startCount(auth()->user(), $this->countOutlet(), $this->note ?: null);
        $this->reset('note');
        $this->open($count->id);
        unset($this->counts);
    }

    public function open(int $id): void
    {
        $this->countId = $id;
        unset($this->current);
        abort_unless($this->current, 404);

        $this->counted = $this->current->items
            ->mapWithKeys(fn ($i) => [$i->id => $i->counted_quantity === null ? '' : (string) (float) $i->counted_quantity])
            ->all();
    }

    public function close(): void
    {
        $this->reset('countId', 'counted');
    }

    public function complete(): void
    {
        $this->countOutlet();
        $count = $this->current;
        abort_unless($count, 404);

        app(StockOperations::class)->completeCount(auth()->user(), $count, $this->counted);

        unset($this->current, $this->counts);
        $this->dispatch('toast', type: 'success', message: 'Stok opname selesai, selisih dibukukan');
    }
}; ?>

<div class="flex flex-col gap-5 max-w-5xl">
    <div class="flex items-center gap-2 text-[12.5px] text-pb-muted -mb-1">
        <a href="{{ route('inventory.index') }}" wire:navigate class="link"><i class="fa-solid fa-arrow-left mr-1"></i>Stok Bahan</a>
        <span>/</span><span>Stok Opname · {{ $this->outlet?->name }}</span>
    </div>

    @if ($count = $this->current)
        @php
            $tz = $this->outlet->business->timezone;
            $diffValue = 0;
        @endphp
        <div class="card overflow-hidden animate-fadeUp">
            <div class="card-header flex-wrap gap-2">
                <div>
                    <div class="card-title"><i class="fa-solid fa-clipboard-check"></i>Opname #{{ $count->id }}</div>
                    <div class="section-desc">
                        Dimulai {{ $count->created_at->timezone($tz)->format('d/m/Y H:i') }}
                        @if (! $count->isDraft()) · selesai {{ $count->completed_at->timezone($tz)->format('d/m/Y H:i') }} @endif
                        {{ $count->note ? ' · '.$count->note : '' }}
                    </div>
                </div>
                <div class="flex gap-2">
                    <span class="badge {{ $count->isDraft() ? 'badge-amber' : 'badge-green' }}">{{ $count->isDraft() ? 'Draft' : 'Selesai' }}</span>
                    <button type="button" wire:click="close" class="btn-ghost text-[12px]">Tutup</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>Bahan</th><th class="text-right">Stok sistem</th><th class="w-44">Hitungan fisik</th><th class="text-right">Selisih</th><th class="text-right">Nilai selisih</th></tr></thead>
                    <tbody>
                        @foreach ($count->items as $item)
                            @php
                                $value = $counted[$item->id] ?? '';
                                $diff = $count->isDraft()
                                    ? ($value === '' || ! is_numeric($value) ? null : (float) $value - (float) $item->system_quantity)
                                    : ($item->difference === null ? null : (float) $item->difference);
                                $diffMoney = $diff === null ? 0 : (int) round($diff * (float) $item->unit_cost);
                                $diffValue += $diffMoney;
                                $unit = $item->ingredient->base_unit->value;
                            @endphp
                            <tr wire:key="sci-{{ $item->id }}">
                                <td class="td-main">{{ $item->ingredient->name }}</td>
                                <td class="text-right whitespace-nowrap">{{ Ingredient::qty($item->system_quantity) }} {{ $unit }}</td>
                                <td>
                                    @if ($count->isDraft())
                                        <input type="number" step="0.001" min="0" wire:model.live.debounce.400ms="counted.{{ $item->id }}" class="f-input !py-1.5" placeholder="Belum dihitung" aria-label="Hitungan {{ $item->ingredient->name }}">
                                        <x-input-error :messages="$errors->get('counted.'.$item->id)" class="mt-1" />
                                    @else
                                        {{ $item->counted_quantity === null ? '—' : Ingredient::qty($item->counted_quantity).' '.$unit }}
                                    @endif
                                </td>
                                <td @class(['text-right font-semibold whitespace-nowrap', 'trend-up' => $diff > 0, 'trend-down' => $diff < 0])>
                                    {{ $diff === null ? '—' : ($diff > 0 ? '+' : '').Ingredient::qty($diff).' '.$unit }}
                                </td>
                                <td class="text-right whitespace-nowrap">{{ $diff ? Money::format($diffMoney) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4 border-t border-pb-border flex flex-wrap items-center justify-between gap-3">
                <div class="text-[13px]">Total nilai selisih: <b @class(['trend-up' => $diffValue > 0, 'trend-down' => $diffValue < 0])>{{ Money::format($diffValue) }}</b></div>
                @if ($count->isDraft())
                    <div class="flex items-center gap-3">
                        <span class="text-[12px] text-pb-muted">Bahan yang tidak diisi dianggap sesuai sistem.</span>
                        <button type="button" wire:click="complete" wire:confirm="Selesaikan opname? Selisih akan dibukukan ke stok dan tidak bisa diubah." class="btn btn-primary">
                            <i class="fa-solid fa-check"></i>Selesaikan Opname
                        </button>
                    </div>
                @endif
            </div>
            <x-input-error :messages="$errors->get('count')" class="px-5 pb-4" />
        </div>
    @else
        <form wire:submit="start" class="card animate-fadeUp">
            <div class="card-header">
                <div>
                    <div class="card-title"><i class="fa-solid fa-clipboard-list"></i>Mulai Stok Opname</div>
                    <div class="section-desc">Stok sistem dicatat saat opname dimulai. Hitung fisik semua bahan, isi jumlahnya, lalu selesaikan.</div>
                </div>
            </div>
            <div class="card-body flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[220px]">
                    <x-input-label for="note" value="Catatan (opsional)" />
                    <x-text-input wire:model="note" id="note" placeholder="mis. Opname akhir bulan" />
                </div>
                <x-primary-button wire:loading.attr="disabled" wire:target="start"><i class="fa-solid fa-play"></i>Mulai</x-primary-button>
            </div>
            <x-input-error :messages="$errors->get('count')" class="px-5 pb-4" />
        </form>

        <div class="card overflow-hidden animate-fadeUp d1">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i>Riwayat Opname</div></div>
            <table class="tbl">
                <thead><tr><th>#</th><th>Dimulai</th><th>Oleh</th><th>Dihitung</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($this->counts as $row)
                        <tr wire:key="sc-{{ $row->id }}">
                            <td class="td-main">{{ $row->id }}</td>
                            <td>{{ $row->created_at->timezone($this->outlet->business->timezone)->format('d/m/Y H:i') }}</td>
                            <td>{{ $row->user?->name }}</td>
                            <td>{{ $row->counted_items }} bahan</td>
                            <td><span class="badge {{ $row->isDraft() ? 'badge-amber' : 'badge-green' }}">{{ $row->isDraft() ? 'Draft' : 'Selesai' }}</span></td>
                            <td class="text-right"><button type="button" wire:click="open({{ $row->id }})" class="btn-ghost font-semibold">{{ $row->isDraft() ? 'Lanjutkan' : 'Lihat' }}</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center !py-8">Belum ada stok opname.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
