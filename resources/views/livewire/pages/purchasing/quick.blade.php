<?php

use App\Enums\PaymentMethod;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\QuickPurchase;
use App\Support\Money;
use App\Support\Purchasing\QuickPurchaseService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Belanja Cepat')] class extends Component
{
    use WithPagination;

    // Form belanja
    public string $purchasedAt = '';
    public string $storeName = '';
    public string $method = 'cash';
    public string $note = '';
    /** @var list<array{ingredient_id: string, quantity: string, unit: string, total_price: string}> */
    public array $items = [];

    #[Computed]
    public function outlet(): ?Outlet
    {
        return auth()->user()->currentOutlet();
    }

    private function stockOutlet(): Outlet
    {
        $outlet = $this->outlet;
        abort_unless($outlet && auth()->user()->canAccessOutlet($outlet), 403);

        return $outlet;
    }

    #[Computed]
    public function ingredients()
    {
        return auth()->user()->activeBusiness()->ingredients()->where('is_active', true)->orderBy('name')->get()->keyBy('id');
    }

    #[Computed]
    public function purchases()
    {
        return QuickPurchase::where('outlet_id', $this->outlet?->id ?? 0)
            ->with(['items.ingredient', 'user'])
            ->latest('purchased_at')->latest('id')
            ->paginate(20);
    }

    #[Computed]
    public function monthTotal(): int
    {
        $start = now(auth()->user()->activeBusiness()->timezone)->startOfMonth()->toDateString();

        return (int) QuickPurchase::where('outlet_id', $this->outlet?->id ?? 0)->whereNull('cancelled_at')
            ->whereDate('purchased_at', '>=', $start)->sum('total');
    }

    #[On('quick-purchase-open')]
    public function openForm(): void
    {
        $this->resetValidation();
        $this->reset('storeName', 'method', 'note');
        $this->purchasedAt = now(auth()->user()->activeBusiness()->timezone)->toDateString();
        $this->items = [];
        $this->addItem();
        $this->dispatch('open-modal', name: 'quick-purchase');
    }

    public function addItem(): void
    {
        $this->items[] = ['ingredient_id' => '', 'quantity' => '', 'unit' => 'base', 'total_price' => ''];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save(QuickPurchaseService $service): void
    {
        $purchase = $service->record(auth()->user(), $this->stockOutlet(), [
            'purchased_at' => $this->purchasedAt,
            'store_name' => $this->storeName,
            'method' => $this->method,
            'note' => $this->note,
            'items' => array_values(array_filter($this->items, fn ($item) => $item['ingredient_id'] !== '')),
        ]);

        $this->dispatch('close-modal', name: 'quick-purchase');
        $this->dispatch('toast', type: 'success', message: 'Belanja '.Money::format($purchase->total).' dicatat, stok bertambah');
        unset($this->purchases, $this->monthTotal);
    }

    public function cancel(QuickPurchaseService $service, int $id): void
    {
        $purchase = QuickPurchase::where('outlet_id', $this->stockOutlet()->id)->findOrFail($id);
        $service->cancel(auth()->user(), $purchase);

        $this->dispatch('toast', type: 'success', message: 'Belanja dibatalkan, stok dikembalikan');
        unset($this->purchases, $this->monthTotal);
    }
}; ?>

<x-slot name="actions">
    <a href="{{ route('purchasing.index') }}" wire:navigate class="btn btn-secondary !px-3 sm:!px-[18px]"><i class="fa-solid fa-truck"></i><span class="hidden sm:inline">Purchase Order</span></a>
    <button type="button" x-data x-on:click="Livewire.dispatch('quick-purchase-open')" class="btn btn-primary !px-3 sm:!px-[18px]"><i class="fa-solid fa-basket-shopping"></i><span class="hidden sm:inline">Catat Belanja</span></button>
</x-slot>

<div class="flex flex-col gap-5">
    <div class="text-[12.5px] text-pb-muted max-w-3xl -mb-1">
        Untuk belanja bahan tanpa supplier tetap, mis. ke pasar atau warung. Stok langsung bertambah dan uangnya tercatat di arus kas.
        Biaya bahan masuk ke laba lewat HPP resep, jadi <b>jangan dicatat lagi</b> di Pengeluaran. Bila menu belum memakai resep, catat belanja di Pengeluaran kategori Bahan Baku saja.
    </div>

    <div class="stat-grid !grid-cols-1 sm:!grid-cols-2 max-w-xl">
        <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Belanja bulan ini</div><div class="stat-num order-first whitespace-nowrap !text-[22px] trend-down">{{ Money::format($this->monthTotal) }}</div></div></div>
    </div>

    <div class="card overflow-hidden animate-fadeUp">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-basket-shopping"></i>Riwayat Belanja · {{ $this->outlet?->name }}</div></div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Tanggal</th><th>Tempat</th><th>Bahan</th><th>Metode</th><th class="text-right">Total</th><th></th></tr></thead>
                <tbody>
                    @forelse ($this->purchases as $purchase)
                        <tr wire:key="qp-{{ $purchase->id }}" @class(['opacity-60' => $purchase->isCancelled()])>
                            <td class="whitespace-nowrap">{{ $purchase->purchased_at->format('d/m/Y') }}</td>
                            <td>{{ $purchase->store_name ?: '—' }}</td>
                            <td class="!whitespace-normal">
                                {{ $purchase->items->map(fn ($item) => $item->ingredient->name.' '.Ingredient::qty($item->base_quantity).' '.$item->ingredient->base_unit->value)->join(', ') }}
                                <div class="text-[11px] text-pb-muted">{{ $purchase->user?->name }}@if ($purchase->note) · {{ $purchase->note }}@endif</div>
                            </td>
                            <td>{{ $purchase->method->label() }}</td>
                            <td class="text-right font-semibold whitespace-nowrap">{{ Money::format($purchase->total) }}</td>
                            <td class="text-right whitespace-nowrap">
                                @if ($purchase->isCancelled())
                                    <span class="badge badge-gray">Dibatalkan</span>
                                @else
                                    <button type="button" wire:click="cancel({{ $purchase->id }})" wire:confirm="Batalkan belanja ini? Stok bahannya akan dikurangi kembali." class="btn-ghost font-semibold text-[12.5px]" style="color:#f87171">Batalkan</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center !py-8">Belum ada belanja cepat di outlet ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->purchases->hasPages())
            <div class="px-5 py-3 border-t border-pb-border">{{ $this->purchases->links() }}</div>
        @endif
    </div>

    <x-modal name="quick-purchase" maxWidth="2xl" focusable>
        <form wire:submit="save">
            <div class="px-6 py-5 border-b border-pb-border">
                <div class="font-display text-base font-bold text-pb-text">Catat Belanja Cepat</div>
                <div class="text-xs text-pb-muted mt-px">Isi harga total yang dibayar per bahan; harga per satuan dihitung otomatis.</div>
            </div>
            <div class="px-6 py-5 flex flex-col gap-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <x-input-label for="purchasedAt" value="Tanggal" />
                        <x-text-input wire:model="purchasedAt" id="purchasedAt" type="date" required />
                        <x-input-error :messages="$errors->get('purchased_at')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="storeName" value="Tempat belanja (opsional)" />
                        <x-text-input wire:model="storeName" id="storeName" placeholder="mis. Pasar Sentral" maxlength="80" />
                    </div>
                    <div>
                        <x-input-label for="quickMethod" value="Dibayar via" />
                        <select wire:model="method" id="quickMethod" class="f-input">
                            @foreach (PaymentMethod::cases() as $m)
                                <option value="{{ $m->value }}">{{ $m->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    @php $grand = 0; @endphp
                    @foreach ($items as $i => $item)
                        @php
                            $ingredient = $this->ingredients->get((int) $item['ingredient_id']);
                            $grand += (int) ($item['total_price'] ?: 0);
                            $size = $item['unit'] === 'purchase' ? (float) ($ingredient?->purchase_unit_size ?: 0) : 1.0;
                            $base = (float) ($item['quantity'] ?: 0) * $size;
                        @endphp
                        <div class="grid gap-2 items-start sm:grid-cols-[1fr_100px_110px_140px_auto]" wire:key="qpi-{{ $i }}">
                            <div>
                                <select wire:model.live="items.{{ $i }}.ingredient_id" class="f-input" aria-label="Bahan">
                                    <option value="">— Pilih bahan —</option>
                                    @foreach ($this->ingredients as $option)
                                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('items.'.$i.'.ingredient_id')" class="mt-1" />
                                @if ($ingredient && $base > 0 && (int) $item['total_price'] > 0)
                                    <div class="text-[11px] text-pb-muted mt-1">≈ {{ Money::format((int) round((int) $item['total_price'] / $base)) }} / {{ $ingredient->base_unit->value }}</div>
                                @endif
                            </div>
                            <div>
                                <input type="number" step="0.001" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.quantity" class="f-input" placeholder="Jumlah" aria-label="Jumlah">
                                <x-input-error :messages="$errors->get('items.'.$i.'.quantity')" class="mt-1" />
                            </div>
                            <div>
                                <select wire:model.live="items.{{ $i }}.unit" class="f-input" aria-label="Satuan">
                                    <option value="base">{{ $ingredient?->base_unit->value ?? 'satuan dasar' }}</option>
                                    @if ($ingredient?->purchase_unit)
                                        <option value="purchase">{{ $ingredient->purchase_unit }}</option>
                                    @endif
                                </select>
                                <x-input-error :messages="$errors->get('items.'.$i.'.unit')" class="mt-1" />
                            </div>
                            <div>
                                <input type="number" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.total_price" class="f-input" placeholder="Harga total (Rp)" aria-label="Harga total">
                                <x-input-error :messages="$errors->get('items.'.$i.'.total_price')" class="mt-1" />
                            </div>
                            <button type="button" wire:click="removeItem({{ $i }})" class="icon-btn !w-10 !h-10" aria-label="Hapus"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                    @endforeach
                    <x-input-error :messages="$errors->get('items')" />
                    @if ($this->ingredients->isEmpty())
                        <p class="f-hint"><a href="{{ route('recipes.index') }}" wire:navigate class="link">Tambah bahan baku</a> dulu.</p>
                    @endif
                    <div class="flex items-center justify-between mt-1">
                        <button type="button" wire:click="addItem" class="btn-ghost font-semibold"><i class="fa-solid fa-plus mr-1"></i>Tambah bahan</button>
                        <div class="font-display text-lg font-bold text-pb-text">Total {{ Money::format($grand) }}</div>
                    </div>
                </div>

                <div>
                    <x-input-label for="quickNote" value="Catatan (opsional)" />
                    <x-text-input wire:model="note" id="quickNote" maxlength="255" />
                </div>
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-check"></i>Simpan</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
