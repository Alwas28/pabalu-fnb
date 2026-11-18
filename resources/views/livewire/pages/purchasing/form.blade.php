<?php

use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Support\Money;
use App\Support\Purchasing\PurchaseService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?PurchaseOrder $purchaseOrder = null;

    public string $supplierId = '';
    public string $orderDate = '';
    public string $expectedDate = '';
    public string $dueDate = '';
    public string $notes = '';

    /** @var list<array{ingredient_id: string, quantity: string, unit: string, unit_price: string}> */
    public array $items = [];

    public function mount(?PurchaseOrder $purchaseOrder = null): void
    {
        $this->orderDate = now(auth()->user()->activeBusiness()->timezone)->toDateString();

        if ($purchaseOrder?->exists) {
            abort_unless($purchaseOrder->business_id === auth()->user()->activeBusiness()->id && auth()->user()->canAccessOutlet($purchaseOrder->outlet), 404);
            abort_unless($purchaseOrder->isEditable(), 403, 'PO yang sudah dipesan tidak bisa diubah.');

            $this->purchaseOrder = $purchaseOrder;
            $this->fill([
                'supplierId' => (string) $purchaseOrder->supplier_id,
                'orderDate' => $purchaseOrder->order_date->toDateString(),
                'expectedDate' => (string) $purchaseOrder->expected_date?->toDateString(),
                'dueDate' => (string) $purchaseOrder->due_date?->toDateString(),
                'notes' => (string) $purchaseOrder->notes,
                'items' => $purchaseOrder->items->map(fn ($i) => [
                    'ingredient_id' => (string) $i->ingredient_id,
                    'quantity' => (string) (float) $i->quantity,
                    'unit' => (float) $i->unit_size === 1.0 ? 'base' : 'purchase',
                    'unit_price' => (string) $i->unit_price,
                ])->all(),
            ]);
        }

        if ($this->items === []) {
            $this->addItem();
        }
    }

    public function rendering($view): void
    {
        $view->title($this->purchaseOrder ? 'Ubah '.$this->purchaseOrder->number : 'Buat Purchase Order');
    }

    #[Computed]
    public function suppliers()
    {
        return auth()->user()->activeBusiness()->suppliers()->where('is_active', true)->get();
    }

    #[Computed]
    public function ingredients()
    {
        return auth()->user()->activeBusiness()->ingredients()->where('is_active', true)->get()->keyBy('id');
    }

    public function addItem(): void
    {
        $this->items[] = ['ingredient_id' => '', 'quantity' => '', 'unit' => 'purchase', 'unit_price' => ''];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save(bool $order = false): void
    {
        $outlet = $this->purchaseOrder?->outlet ?? auth()->user()->currentOutlet();
        abort_unless($outlet && auth()->user()->canAccessOutlet($outlet), 403);

        $service = app(PurchaseService::class);
        $po = $service->saveDraft(auth()->user(), $outlet, [
            'supplier_id' => $this->supplierId,
            'order_date' => $this->orderDate,
            'expected_date' => $this->expectedDate ?: null,
            'due_date' => $this->dueDate ?: null,
            'notes' => $this->notes ?: null,
            'items' => array_values(array_filter($this->items, fn ($i) => $i['ingredient_id'] !== '')),
        ], $this->purchaseOrder);

        if ($order) {
            $service->markOrdered($po);
        }

        session()->flash('toast', $po->number.($order ? ' dipesan ke supplier' : ' disimpan sebagai draft'));
        $this->redirectRoute('purchasing.show', $po, navigate: true);
    }
}; ?>

<form wire:submit="save" class="flex flex-col gap-5 max-w-5xl">
    <div class="flex items-center gap-2 text-[12.5px] text-pb-muted -mb-1">
        <a href="{{ route('purchasing.index') }}" wire:navigate class="link"><i class="fa-solid fa-arrow-left mr-1"></i>Pembelian</a>
        <span>/</span><span>{{ $purchaseOrder?->number ?? 'PO baru' }}</span>
    </div>

    <div class="card animate-fadeUp">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-file-invoice"></i>Informasi PO · {{ ($purchaseOrder?->outlet ?? auth()->user()->currentOutlet())?->name }}</div></div>
        <div class="card-body grid gap-4 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <x-input-label for="supplierId" value="Supplier" />
                <select wire:model="supplierId" id="supplierId" class="f-input" required>
                    <option value="">— Pilih supplier —</option>
                    @foreach ($this->suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('supplier_id')" class="mt-1.5" />
                @if ($this->suppliers->isEmpty())
                    <p class="f-hint"><a href="{{ route('purchasing.suppliers') }}" wire:navigate class="link">Tambah supplier</a> dulu.</p>
                @endif
            </div>
            <div>
                <x-input-label for="orderDate" value="Tanggal PO" />
                <x-text-input wire:model="orderDate" id="orderDate" type="date" required />
                <x-input-error :messages="$errors->get('order_date')" class="mt-1.5" />
            </div>
            <div>
                <x-input-label for="expectedDate" value="Perkiraan tiba" />
                <x-text-input wire:model="expectedDate" id="expectedDate" type="date" />
                <x-input-error :messages="$errors->get('expected_date')" class="mt-1.5" />
            </div>
            <div>
                <x-input-label for="dueDate" value="Jatuh tempo bayar" />
                <x-text-input wire:model="dueDate" id="dueDate" type="date" />
                <x-input-error :messages="$errors->get('due_date')" class="mt-1.5" />
            </div>
            <div class="sm:col-span-3">
                <x-input-label for="notes" value="Catatan" />
                <x-text-input wire:model="notes" id="notes" />
            </div>
        </div>
    </div>

    <div class="card overflow-hidden animate-fadeUp d1">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-boxes-stacked"></i>Bahan yang Dipesan</div></div>
        <div class="card-body flex flex-col gap-2">
            @php $grand = 0; @endphp
            @foreach ($items as $i => $item)
                @php
                    $ingredient = $this->ingredients->get((int) $item['ingredient_id']);
                    $lineTotal = (int) round((float) ($item['quantity'] ?: 0) * (int) ($item['unit_price'] ?: 0));
                    $grand += $lineTotal;
                @endphp
                <div class="grid gap-2 items-start sm:grid-cols-[1fr_110px_130px_150px_130px_auto]" wire:key="poi-{{ $i }}">
                    <div>
                        <select wire:model.live="items.{{ $i }}.ingredient_id" class="f-input" aria-label="Bahan">
                            <option value="">— Pilih bahan —</option>
                            @foreach ($this->ingredients as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('items.'.$i.'.ingredient_id')" class="mt-1" />
                    </div>
                    <div>
                        <input type="number" step="0.001" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.quantity" class="f-input" placeholder="Jumlah" aria-label="Jumlah">
                        <x-input-error :messages="$errors->get('items.'.$i.'.quantity')" class="mt-1" />
                    </div>
                    <select wire:model.live="items.{{ $i }}.unit" class="f-input" aria-label="Satuan">
                        @if ($ingredient?->purchase_unit)
                            <option value="purchase">{{ $ingredient->purchase_unit }}</option>
                        @endif
                        <option value="base">{{ $ingredient?->base_unit->value ?? 'satuan dasar' }}</option>
                    </select>
                    <div>
                        <input type="number" min="0" wire:model.live.debounce.400ms="items.{{ $i }}.unit_price" class="f-input" placeholder="Harga / satuan" aria-label="Harga per satuan">
                        <x-input-error :messages="$errors->get('items.'.$i.'.unit_price')" class="mt-1" />
                    </div>
                    <div class="f-input !bg-transparent !border-transparent text-right font-semibold">{{ Money::format($lineTotal) }}</div>
                    <button type="button" wire:click="removeItem({{ $i }})" class="icon-btn !w-10 !h-10" aria-label="Hapus"><i class="fa-solid fa-xmark"></i></button>
                </div>
            @endforeach
            <x-input-error :messages="$errors->get('items')" />
            <div class="flex items-center justify-between mt-1">
                <button type="button" wire:click="addItem" class="btn-ghost font-semibold"><i class="fa-solid fa-plus mr-1"></i>Tambah bahan</button>
                <div class="font-display text-lg font-bold text-pb-text">Total {{ Money::format($grand) }}</div>
            </div>
        </div>
    </div>

    <div class="flex justify-end gap-2.5 pb-4">
        <a href="{{ route('purchasing.index') }}" wire:navigate class="btn btn-secondary">Batal</a>
        <button type="submit" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-floppy-disk"></i>Simpan Draft</button>
        <button type="button" wire:click="save(true)" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-paper-plane"></i>Simpan &amp; Pesan</button>
    </div>
</form>
