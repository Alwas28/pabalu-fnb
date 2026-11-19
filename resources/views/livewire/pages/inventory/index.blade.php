<?php

use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Support\Inventory\StockOperations;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Stok Bahan')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'stock';

    public string $search = '';

    /** Filter riwayat */
    #[Url(as: 'bahan')]
    public string $movementIngredient = '';
    public string $movementType = '';

    // Form aksi (waste / adjust)
    public string $action = 'waste';
    public string $ingredientId = '';
    public string $quantity = '';
    public string $unitCost = '';
    public string $reason = '';

    // Form transfer
    public string $toOutletId = '';
    public string $transferNote = '';
    /** @var list<array{ingredient_id: string, quantity: string}> */
    public array $transferItems = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['movementIngredient', 'movementType', 'tab'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function outlet(): ?Outlet
    {
        return auth()->user()->currentOutlet();
    }

    /** Semua bahan aktif beserta saldo di outlet aktif. */
    #[Computed]
    public function rows()
    {
        $outlet = $this->outlet;
        $stocks = $outlet ? $outlet->ingredientStocks()->get()->keyBy('ingredient_id') : collect();

        return auth()->user()->activeBusiness()->ingredients()
            ->where('is_active', true)
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->get()
            ->map(function (Ingredient $ingredient) use ($stocks) {
                $stock = $stocks->get($ingredient->id);
                $qty = (float) ($stock->quantity ?? 0);

                return (object) [
                    'ingredient' => $ingredient,
                    'quantity' => $qty,
                    'avg_cost' => (float) ($stock->avg_cost ?? 0),
                    'value' => $stock?->value() ?? 0,
                    'status' => $qty < 0 ? 'minus' : ($qty <= (float) $ingredient->min_stock ? 'low' : 'ok'),
                ];
            })
            ->sortBy(fn ($r) => ['minus' => 0, 'low' => 1, 'ok' => 2][$r->status].$r->ingredient->name)
            ->values();
    }

    #[Computed]
    public function movements()
    {
        return StockMovement::where('outlet_id', $this->outlet?->id ?? 0)
            ->with(['ingredient', 'user'])
            ->when($this->movementIngredient !== '', fn ($q) => $q->where('ingredient_id', (int) $this->movementIngredient))
            ->when($this->movementType !== '', fn ($q) => $q->where('type', $this->movementType))
            ->latest('id')
            ->paginate(30);
    }

    #[Computed]
    public function otherOutlets()
    {
        return auth()->user()->accessibleOutlets()->reject(fn ($o) => $o->id === $this->outlet?->id)->values();
    }

    private function stockOutlet(): Outlet
    {
        $outlet = $this->outlet;
        abort_unless($outlet && auth()->user()->canAccessOutlet($outlet), 403);

        return $outlet;
    }

    public function showHistory(int $ingredientId): void
    {
        $this->movementIngredient = (string) $ingredientId;
        $this->tab = 'movements';
        $this->resetPage();
    }

    #[On('stock-action-open')]
    public function openAction(string $action, ?int $ingredientId = null): void
    {
        $this->resetValidation();
        $this->reset('quantity', 'unitCost', 'reason');
        $this->action = in_array($action, ['waste', 'adjust'], true) ? $action : 'waste';
        $this->ingredientId = $ingredientId ? (string) $ingredientId : '';
        $this->dispatch('open-modal', name: 'stock-action');
    }

    public function saveAction(): void
    {
        $outlet = $this->stockOutlet();
        $input = ['ingredient_id' => $this->ingredientId, 'quantity' => $this->quantity, 'unit_cost' => $this->unitCost, 'reason' => $this->reason];

        $this->action === 'waste'
            ? app(StockOperations::class)->waste(auth()->user(), $outlet, $input)
            : app(StockOperations::class)->adjust(auth()->user(), $outlet, $input);

        $this->dispatch('close-modal', name: 'stock-action');
        $this->dispatch('toast', type: 'success', message: $this->action === 'waste' ? 'Waste dicatat' : 'Penyesuaian stok dicatat');
        unset($this->rows, $this->movements);
    }

    #[On('stock-transfer-open')]
    public function openTransfer(): void
    {
        $this->resetValidation();
        $this->reset('toOutletId', 'transferNote');
        $this->transferItems = [['ingredient_id' => '', 'quantity' => '']];
        $this->dispatch('open-modal', name: 'stock-transfer');
    }

    public function addTransferItem(): void
    {
        $this->transferItems[] = ['ingredient_id' => '', 'quantity' => ''];
    }

    public function removeTransferItem(int $index): void
    {
        unset($this->transferItems[$index]);
        $this->transferItems = array_values($this->transferItems);
    }

    public function saveTransfer(): void
    {
        $transfer = app(StockOperations::class)->transfer(auth()->user(), $this->stockOutlet(), [
            'to_outlet_id' => $this->toOutletId,
            'note' => $this->transferNote,
            'items' => array_values(array_filter($this->transferItems, fn ($i) => $i['ingredient_id'] !== '')),
        ]);

        $this->dispatch('close-modal', name: 'stock-transfer');
        $this->dispatch('toast', type: 'success', message: 'Transfer ke '.$transfer->toOutlet->name.' dicatat');
        unset($this->rows, $this->movements);
    }
}; ?>

<x-slot name="actions">
    <a href="{{ route('inventory.counts.index') }}" wire:navigate class="btn btn-secondary !px-3 sm:!px-[18px]"><i class="fa-solid fa-clipboard-check"></i><span class="hidden sm:inline">Stok Opname</span></a>
    <button type="button" x-data x-on:click="Livewire.dispatch('stock-transfer-open')" class="btn btn-secondary !px-3 sm:!px-[18px]"><i class="fa-solid fa-truck-ramp-box"></i><span class="hidden sm:inline">Transfer</span></button>
    <button type="button" x-data x-on:click="Livewire.dispatch('stock-action-open', { action: 'waste' })" class="btn btn-primary !px-3 sm:!px-[18px]"><i class="fa-solid fa-trash-can"></i><span class="hidden sm:inline">Catat Waste</span></button>
</x-slot>

<div class="flex flex-col gap-5">
    @php
        $outlet = $this->outlet;
        $rows = $this->rows;
    @endphp

    @if (! $outlet)
        <div class="card"><div class="card-body text-center text-pb-muted">Belum ada outlet yang ditugaskan.</div></div>
    @else
        <div class="stat-grid">
            <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Nilai persediaan</div><div class="stat-num order-first whitespace-nowrap !text-[22px]">{{ Money::format($rows->sum('value')) }}</div></div></div>
            <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Bahan aktif</div><div class="stat-num order-first whitespace-nowrap !text-[22px]">{{ $rows->count() }}</div></div></div>
            <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Di bawah minimum</div><div class="stat-num order-first whitespace-nowrap !text-[22px] trend-down">{{ $rows->where('status', 'low')->count() }}</div></div></div>
            <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Stok minus</div><div class="stat-num order-first whitespace-nowrap !text-[22px] trend-down">{{ $rows->where('status', 'minus')->count() }}</div></div></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="$set('tab', 'stock')" @class(['chip cursor-pointer', 'a-bg-lt a-text a-border' => $tab === 'stock'])>Saldo Stok</button>
            <button type="button" wire:click="$set('tab', 'movements')" @class(['chip cursor-pointer', 'a-bg-lt a-text a-border' => $tab === 'movements'])>Riwayat Pergerakan</button>
            <span class="text-[12px] text-pb-muted ml-2">
                {{ $outlet->recipe_stock_deduction ? 'Stok berkurang otomatis dari resep saat penjualan.' : 'Pengurangan stok otomatis dari resep nonaktif di outlet ini.' }}
            </span>
        </div>

        @if ($tab === 'stock')
            <div class="card overflow-hidden animate-fadeUp">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-warehouse"></i>Stok {{ $outlet->name }}</div>
                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-pb-muted text-xs"></i>
                        <input type="search" wire:model.live.debounce.300ms="search" class="f-input !py-1.5 !pl-8 !w-52 !text-[12.5px]" placeholder="Cari bahan">
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead><tr><th>Bahan</th><th class="text-right">Stok</th><th class="text-right">Minimum</th><th>Status</th><th class="text-right">HPP / satuan</th><th class="text-right">Nilai</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($rows as $row)
                                @php $unit = $row->ingredient->base_unit->value; @endphp
                                <tr wire:key="st-{{ $row->ingredient->id }}">
                                    <td class="td-main">{{ $row->ingredient->name }}</td>
                                    <td class="text-right font-semibold whitespace-nowrap">{{ Ingredient::qty($row->quantity) }} {{ $unit }}</td>
                                    <td class="text-right whitespace-nowrap text-pb-muted">{{ Ingredient::qty($row->ingredient->min_stock) }} {{ $unit }}</td>
                                    <td>
                                        <span class="badge {{ ['ok' => 'badge-green', 'low' => 'badge-amber', 'minus' => 'badge-red'][$row->status] }}">
                                            {{ ['ok' => 'Aman', 'low' => 'Menipis', 'minus' => 'Minus'][$row->status] }}
                                        </span>
                                    </td>
                                    <td class="text-right whitespace-nowrap">Rp {{ number_format($row->avg_cost, 2, ',', '.') }}</td>
                                    <td class="text-right whitespace-nowrap">{{ Money::format($row->value) }}</td>
                                    <td class="text-right whitespace-nowrap">
                                        <button type="button" wire:click="showHistory({{ $row->ingredient->id }})" class="btn-ghost text-[12px]">Riwayat</button>
                                        <button type="button" wire:click="openAction('adjust', {{ $row->ingredient->id }})" class="btn-ghost text-[12px] font-semibold">Sesuaikan</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center !py-8">
                                    Belum ada bahan baku.
                                    @can('recipes.manage')<a href="{{ route('recipes.index') }}" wire:navigate class="link">Tambah bahan</a>@endcan
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <div class="card overflow-hidden animate-fadeUp">
                <div class="card-header flex-wrap gap-2">
                    <div class="card-title"><i class="fa-solid fa-list"></i>Riwayat Pergerakan</div>
                    <div class="flex gap-2">
                        <select wire:model.live="movementIngredient" class="f-input !py-1.5 !w-48 !text-[12.5px]" aria-label="Bahan">
                            <option value="">Semua bahan</option>
                            @foreach ($rows as $row)
                                <option value="{{ $row->ingredient->id }}">{{ $row->ingredient->name }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="movementType" class="f-input !py-1.5 !w-48 !text-[12.5px]" aria-label="Jenis">
                            <option value="">Semua jenis</option>
                            @foreach (StockMovementType::cases() as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead><tr><th>Waktu</th><th>Bahan</th><th>Jenis</th><th class="text-right">Jumlah</th><th class="text-right">Saldo</th><th>Keterangan</th><th>Oleh</th></tr></thead>
                        <tbody>
                            @forelse ($this->movements as $movement)
                                <tr wire:key="mv-{{ $movement->id }}">
                                    <td class="whitespace-nowrap">{{ $movement->created_at->timezone($outlet->business->timezone)->format('d/m H:i') }}</td>
                                    <td class="td-main">{{ $movement->ingredient->name }}</td>
                                    <td><span class="badge {{ $movement->type->badge() }}">{{ $movement->type->label() }}</span></td>
                                    <td @class(['text-right font-semibold whitespace-nowrap', 'trend-up' => $movement->quantity > 0, 'trend-down' => $movement->quantity < 0])>
                                        {{ $movement->quantity > 0 ? '+' : '' }}{{ Ingredient::qty($movement->quantity) }} {{ $movement->ingredient->base_unit->value }}
                                    </td>
                                    <td class="text-right whitespace-nowrap">{{ Ingredient::qty($movement->balance_after) }}</td>
                                    <td class="!whitespace-normal text-[12px]">{{ $movement->note }}</td>
                                    <td class="text-[12px]">{{ $movement->user?->name ?? 'Sistem' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center !py-8">Belum ada pergerakan stok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($this->movements->hasPages())
                    <div class="px-5 py-3 border-t border-pb-border">{{ $this->movements->links() }}</div>
                @endif
            </div>
        @endif

        {{-- ── Waste / penyesuaian ── --}}
        <x-modal name="stock-action" maxWidth="md" focusable>
            <form wire:submit="saveAction">
                <div class="px-6 py-5 border-b border-pb-border">
                    <div class="font-display text-base font-bold text-pb-text">{{ $action === 'waste' ? 'Catat Waste' : 'Penyesuaian Stok' }}</div>
                    <div class="text-xs text-pb-muted mt-px">{{ $action === 'waste' ? 'Bahan rusak, tumpah, atau kedaluwarsa.' : 'Koreksi stok: isi positif untuk menambah, negatif untuk mengurangi.' }}</div>
                </div>
                <div class="px-6 py-5 flex flex-col gap-4">
                    <div class="grid grid-cols-2 gap-2">
                        <label class="choice-card !p-3 !items-center"><input type="radio" wire:model.live="action" value="waste"><span class="text-[13px] font-semibold text-pb-text">Waste</span></label>
                        <label class="choice-card !p-3 !items-center"><input type="radio" wire:model.live="action" value="adjust"><span class="text-[13px] font-semibold text-pb-text">Penyesuaian</span></label>
                    </div>
                    <div>
                        <x-input-label for="ingredientId" value="Bahan" />
                        <select wire:model.live="ingredientId" id="ingredientId" class="f-input" required>
                            <option value="">— Pilih bahan —</option>
                            @foreach ($rows as $row)
                                <option value="{{ $row->ingredient->id }}">{{ $row->ingredient->name }} · stok {{ Ingredient::qty($row->quantity) }} {{ $row->ingredient->base_unit->value }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('ingredient_id')" class="mt-1.5" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="quantity" value="Jumlah ({{ $rows->firstWhere('ingredient.id', (int) $ingredientId)?->ingredient->base_unit->value ?? 'satuan dasar' }})" />
                            <x-text-input wire:model.live.debounce.300ms="quantity" id="quantity" type="number" step="0.001" required />
                            <x-input-error :messages="$errors->get('quantity')" class="mt-1.5" />
                        </div>
                        @if ($action === 'adjust' && (float) $quantity > 0)
                            <div>
                                <x-input-label for="unitCost" value="Nilai / satuan (opsional)" />
                                <x-text-input wire:model="unitCost" id="unitCost" type="number" step="0.0001" min="0" placeholder="HPP saat ini" />
                                <x-input-error :messages="$errors->get('unit_cost')" class="mt-1.5" />
                            </div>
                        @endif
                    </div>
                    <div>
                        <x-input-label for="reason" value="Alasan" />
                        <x-text-input wire:model="reason" id="reason" placeholder="{{ $action === 'waste' ? 'mis. Susu basi' : 'mis. Koreksi salah input' }}" required />
                        <x-input-error :messages="$errors->get('reason')" class="mt-1.5" />
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled" wire:target="saveAction"><i class="fa-solid fa-check"></i>Simpan</x-primary-button>
                </div>
            </form>
        </x-modal>

        {{-- ── Transfer antar outlet ── --}}
        <x-modal name="stock-transfer" maxWidth="lg" focusable>
            <form wire:submit="saveTransfer">
                <div class="px-6 py-5 border-b border-pb-border">
                    <div class="font-display text-base font-bold text-pb-text">Transfer Bahan</div>
                    <div class="text-xs text-pb-muted mt-px">Dari {{ $outlet->name }}. Stok langsung berpindah; HPP ikut dibawa.</div>
                </div>
                <div class="px-6 py-5 flex flex-col gap-4 max-h-[60vh] overflow-y-auto">
                    <div>
                        <x-input-label for="toOutletId" value="Ke outlet" />
                        <select wire:model="toOutletId" id="toOutletId" class="f-input" required>
                            <option value="">— Pilih outlet tujuan —</option>
                            @foreach ($this->otherOutlets as $other)
                                <option value="{{ $other->id }}">{{ $other->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('to_outlet_id')" class="mt-1.5" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <x-input-label value="Bahan" />
                        @foreach ($transferItems as $i => $item)
                            <div class="grid grid-cols-[1fr_130px_auto] gap-2 items-start" wire:key="ti-{{ $i }}">
                                <div>
                                    <select wire:model="transferItems.{{ $i }}.ingredient_id" class="f-input" aria-label="Bahan">
                                        <option value="">— Pilih bahan —</option>
                                        @foreach ($rows as $row)
                                            <option value="{{ $row->ingredient->id }}">{{ $row->ingredient->name }} ({{ Ingredient::qty($row->quantity) }} {{ $row->ingredient->base_unit->value }})</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('items.'.$i.'.ingredient_id')" class="mt-1" />
                                </div>
                                <div>
                                    <input type="number" step="0.001" min="0" wire:model="transferItems.{{ $i }}.quantity" class="f-input" placeholder="Jumlah" aria-label="Jumlah">
                                    <x-input-error :messages="$errors->get('items.'.$i.'.quantity')" class="mt-1" />
                                </div>
                                <button type="button" wire:click="removeTransferItem({{ $i }})" class="icon-btn !w-9 !h-9" aria-label="Hapus"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                        @endforeach
                        <x-input-error :messages="$errors->get('items')" />
                        <div><button type="button" wire:click="addTransferItem" class="btn-ghost font-semibold"><i class="fa-solid fa-plus mr-1"></i>Tambah bahan</button></div>
                    </div>
                    <div>
                        <x-input-label for="transferNote" value="Catatan (opsional)" />
                        <x-text-input wire:model="transferNote" id="transferNote" />
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled" wire:target="saveTransfer"><i class="fa-solid fa-truck-ramp-box"></i>Transfer</x-primary-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
