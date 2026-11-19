<?php

use App\Enums\StockUnit;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\RecipeItem;
use App\Support\Inventory\RecipeUsage;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Resep & Bahan')] class extends Component
{
    #[Url]
    public string $tab = 'ingredients';

    public string $search = '';

    // Form bahan baku
    public ?int $ingredientId = null;
    public string $name = '';
    public string $sku = '';
    public string $baseUnit = 'g';
    public string $purchaseUnit = '';
    public string $purchaseUnitSize = '';
    public string $minStock = '0';
    public bool $isActive = true;

    // Editor resep: menu (menu_item_id) atau modifier (modifier_id)
    public string $recipeOwner = '';      // 'menu' / 'modifier'
    public ?int $recipeOwnerId = null;
    /** @var list<array{ingredient_id: string, quantity: string, variant_id: string}> */
    public array $recipeRows = [];

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    #[Computed]
    public function outlet()
    {
        return auth()->user()->currentOutlet();
    }

    #[Computed]
    public function ingredients()
    {
        $outletId = $this->outlet?->id;

        return $this->business()->ingredients()
            ->when($this->search !== '' && $this->tab === 'ingredients', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->with(['stocks' => fn ($q) => $q->where('outlet_id', $outletId)])
            ->get();
    }

    #[Computed]
    public function menuItems()
    {
        return $this->business()->menuItems()
            ->with(['category', 'variants', 'recipeItems.ingredient', 'outlets' => fn ($q) => $q->whereKey($this->outlet?->id ?? 0)])
            ->when($this->search !== '' && $this->tab === 'menu', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function modifiers()
    {
        return Modifier::query()
            ->whereHas('group', fn ($q) => $q->where('business_id', $this->business()->id))
            ->with(['group', 'recipeItems.ingredient'])
            ->get()
            ->sortBy(fn ($m) => $m->group->name.' '.$m->sort_order);
    }

    /** HPP rata-rata outlet aktif per bahan. */
    #[Computed]
    public function costs(): array
    {
        return $this->outlet ? $this->outlet->ingredientStocks()->pluck('avg_cost', 'ingredient_id')->map(fn ($v) => (float) $v)->all() : [];
    }

    /* ─────────────── Bahan baku ─────────────── */

    #[On('ingredient-create')]
    public function createIngredient(): void
    {
        $this->resetIngredientForm();
        $this->dispatch('open-modal', name: 'ingredient-form');
    }

    public function editIngredient(int $id): void
    {
        $ingredient = $this->findIngredient($id);
        $this->resetIngredientForm();
        $this->fill([
            'ingredientId' => $ingredient->id,
            'name' => $ingredient->name,
            'sku' => (string) $ingredient->sku,
            'baseUnit' => $ingredient->base_unit->value,
            'purchaseUnit' => (string) $ingredient->purchase_unit,
            'purchaseUnitSize' => $ingredient->purchase_unit_size ? (string) (float) $ingredient->purchase_unit_size : '',
            'minStock' => (string) (float) $ingredient->min_stock,
            'isActive' => $ingredient->is_active,
        ]);
        $this->dispatch('open-modal', name: 'ingredient-form');
    }

    public function saveIngredient(): void
    {
        $businessId = $this->business()->id;

        $this->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('ingredients', 'name')->where('business_id', $businessId)->ignore($this->ingredientId)],
            'sku' => ['nullable', 'string', 'max:40', Rule::unique('ingredients', 'sku')->where('business_id', $businessId)->ignore($this->ingredientId)],
            'baseUnit' => ['required', Rule::enum(StockUnit::class)],
            'purchaseUnit' => ['nullable', 'string', 'max:20', 'required_with:purchaseUnitSize'],
            'purchaseUnitSize' => ['nullable', 'numeric', 'gt:0', 'required_with:purchaseUnit'],
            'minStock' => ['required', 'numeric', 'min:0'],
            'isActive' => ['boolean'],
        ], [
            'name.unique' => 'Bahan dengan nama ini sudah ada.',
            'purchaseUnitSize.required_with' => 'Isi berapa satuan dasar dalam 1 satuan beli.',
        ]);

        // Satuan dasar tidak boleh berubah setelah ada pergerakan stok (angka historis akan salah arti).
        if ($this->ingredientId) {
            $ingredient = $this->findIngredient($this->ingredientId);
            if ($ingredient->base_unit->value !== $this->baseUnit && $ingredient->movements()->exists()) {
                $this->addError('baseUnit', 'Satuan dasar tidak bisa diubah karena bahan ini sudah punya riwayat stok.');

                return;
            }
        }

        $data = [
            'name' => $this->name,
            'sku' => $this->sku ?: null,
            'base_unit' => $this->baseUnit,
            'purchase_unit' => $this->purchaseUnit ?: null,
            'purchase_unit_size' => $this->purchaseUnitSize !== '' ? $this->purchaseUnitSize : null,
            'min_stock' => $this->minStock,
            'is_active' => $this->isActive,
        ];

        $this->ingredientId
            ? $this->findIngredient($this->ingredientId)->update($data)
            : $this->business()->ingredients()->create($data);

        $this->dispatch('close-modal', name: 'ingredient-form');
        $this->dispatch('toast', type: 'success', message: 'Bahan '.$this->name.' disimpan');
        $this->resetIngredientForm();
        unset($this->ingredients);
    }

    /* ─────────────── Resep ─────────────── */

    public function editRecipe(string $owner, int $id): void
    {
        $this->resetValidation();
        $this->recipeOwner = $owner === 'modifier' ? 'modifier' : 'menu';
        $this->recipeOwnerId = $id;

        $rows = $this->recipeOwner === 'menu'
            ? $this->findMenuItem($id)->recipeItems()->orderBy('menu_variant_id')->orderBy('id')->get()
            : $this->findModifier($id)->recipeItems()->orderBy('id')->get();

        $this->recipeRows = $rows->map(fn (RecipeItem $r) => [
            'ingredient_id' => (string) $r->ingredient_id,
            'quantity' => (string) (float) $r->quantity,
            'variant_id' => (string) $r->menu_variant_id,
        ])->all();

        if ($this->recipeRows === []) {
            $this->addRecipeRow();
        }

        $this->dispatch('open-modal', name: 'recipe-form');
    }

    public function addRecipeRow(): void
    {
        $this->recipeRows[] = ['ingredient_id' => '', 'quantity' => '', 'variant_id' => ''];
    }

    public function removeRecipeRow(int $index): void
    {
        unset($this->recipeRows[$index]);
        $this->recipeRows = array_values($this->recipeRows);
    }

    public function saveRecipe(): void
    {
        $menuItem = $this->recipeOwner === 'menu' ? $this->findMenuItem($this->recipeOwnerId) : null;
        $modifier = $this->recipeOwner === 'modifier' ? $this->findModifier($this->recipeOwnerId) : null;
        $variantIds = $menuItem?->variants->pluck('id')->map(fn ($v) => (string) $v)->all() ?? [];

        // Baris kosong diabaikan.
        $this->recipeRows = array_values(array_filter($this->recipeRows, fn ($r) => $r['ingredient_id'] !== '' || $r['quantity'] !== ''));

        $this->validate([
            'recipeRows' => ['array', 'max:40'],
            'recipeRows.*.ingredient_id' => ['required', Rule::exists('ingredients', 'id')->where('business_id', $this->business()->id)],
            'recipeRows.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'recipeRows.*.variant_id' => ['nullable', Rule::in($variantIds)],
        ], [], ['recipeRows.*.ingredient_id' => 'bahan', 'recipeRows.*.quantity' => 'jumlah']);

        $owner = $menuItem ?? $modifier;
        $describe = fn ($rows) => collect($rows)->map(fn ($r) => $r->ingredient->name.' '.Ingredient::qty($r->quantity).$r->ingredient->base_unit->value
            .($r->menu_variant_id ? ' (+'.($menuItem?->variants->firstWhere('id', $r->menu_variant_id)?->name).')' : ''))->sort()->join(', ');
        $before = $describe($owner->recipeItems()->with('ingredient')->get());

        DB::transaction(function () use ($menuItem, $modifier) {
            $menuItem ? $menuItem->recipeItems()->delete() : $modifier->recipeItems()->delete();

            foreach ($this->recipeRows as $row) {
                RecipeItem::create([
                    'menu_item_id' => $menuItem?->id,
                    'menu_variant_id' => $menuItem && $row['variant_id'] !== '' ? (int) $row['variant_id'] : null,
                    'modifier_id' => $modifier?->id,
                    'ingredient_id' => (int) $row['ingredient_id'],
                    'quantity' => $row['quantity'],
                ]);
            }
        });

        $after = $describe($owner->recipeItems()->with('ingredient')->get());
        if ($before !== $after) {
            \App\Models\AuditLog::record($this->business()->id, $owner, 'recipe', $owner instanceof Modifier ? 'Modifier '.$owner->name : $owner->name,
                ['resep' => [$before ?: '—', $after ?: '—']]);
        }

        $this->dispatch('close-modal', name: 'recipe-form');
        $this->dispatch('toast', type: 'success', message: 'Resep '.($menuItem?->name ?? $modifier->name).' disimpan');
        unset($this->menuItems, $this->modifiers);
    }

    public function portionCost(MenuItem $item, ?int $variantId = null): int
    {
        return $this->outlet ? app(RecipeUsage::class)->portionCost($item, $this->outlet->id, $variantId) : 0;
    }

    private function findIngredient(int $id): Ingredient
    {
        $ingredient = $this->business()->ingredients()->find($id);
        abort_unless($ingredient, 404);

        return $ingredient;
    }

    private function findMenuItem(?int $id): MenuItem
    {
        $item = $this->business()->menuItems()->with('variants')->find($id);
        abort_unless($item, 404);

        return $item;
    }

    private function findModifier(?int $id): Modifier
    {
        $modifier = Modifier::whereHas('group', fn ($q) => $q->where('business_id', $this->business()->id))->find($id);
        abort_unless($modifier, 404);

        return $modifier;
    }

    private function resetIngredientForm(): void
    {
        $this->reset('ingredientId', 'name', 'sku', 'baseUnit', 'purchaseUnit', 'purchaseUnitSize', 'minStock', 'isActive');
        $this->resetValidation();
    }
}; ?>

<x-slot name="actions">
    <button type="button" x-data x-on:click="Livewire.dispatch('ingredient-create')" class="btn btn-primary !px-3 sm:!px-[18px]">
        <i class="fa-solid fa-plus"></i><span class="hidden sm:inline">Tambah Bahan</span>
    </button>
</x-slot>

<div class="flex flex-col gap-5">
    @php $costs = $this->costs; @endphp

    <div class="flex flex-wrap items-center gap-2">
        @foreach (['ingredients' => 'Bahan Baku', 'menu' => 'Resep Menu', 'modifiers' => 'Resep Modifier'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['chip cursor-pointer', 'a-bg-lt a-text a-border' => $tab === $key])>{{ $label }}</button>
        @endforeach
        <div class="relative ml-auto">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-pb-muted text-xs"></i>
            <input type="search" wire:model.live.debounce.300ms="search" class="f-input !py-1.5 !pl-8 !w-56 !text-[12.5px]" placeholder="Cari…">
        </div>
    </div>

    @if ($this->outlet)
        <p class="text-[12px] text-pb-muted -mt-2">Biaya bahan memakai HPP rata-rata di <b>{{ $this->outlet->name }}</b>. Ganti outlet di kanan atas untuk melihat outlet lain.</p>
    @endif

    @if ($tab === 'ingredients')
        <div class="card overflow-hidden animate-fadeUp">
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>Bahan</th><th>Satuan</th><th>Satuan beli</th><th class="text-right">Stok min.</th><th class="text-right">HPP / satuan</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($this->ingredients as $ingredient)
                            <tr wire:key="ing-{{ $ingredient->id }}" @class(['opacity-60' => ! $ingredient->is_active])>
                                <td class="td-main">{{ $ingredient->name }} <div class="text-[11px] text-pb-muted font-normal">{{ $ingredient->sku ?: '—' }}</div></td>
                                <td>{{ $ingredient->base_unit->value }}</td>
                                <td>{{ $ingredient->purchaseUnitLabel() ?? '—' }}</td>
                                <td class="text-right">{{ Ingredient::qty($ingredient->min_stock) }}</td>
                                <td class="text-right">{{ isset($costs[$ingredient->id]) ? 'Rp '.number_format($costs[$ingredient->id], 2, ',', '.') : '—' }}</td>
                                <td class="text-right"><button type="button" wire:click="editIngredient({{ $ingredient->id }})" class="btn-ghost font-semibold"><i class="fa-solid fa-pen mr-1"></i>Ubah</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center !py-8">Belum ada bahan baku. Tambahkan bahan seperti "Susu UHT" (ml) atau "Biji Kopi" (g).</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($tab === 'menu')
        <div class="card overflow-hidden animate-fadeUp">
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>Menu</th><th>Resep</th><th class="text-right">Harga</th><th class="text-right">Biaya bahan</th><th class="text-right">Food cost</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($this->menuItems as $item)
                            @php
                                $price = $this->outlet ? $item->priceAt($this->outlet) : $item->price;
                                $cost = $this->portionCost($item);
                                $pct = $price > 0 && $cost > 0 ? $cost / $price * 100 : null;
                            @endphp
                            <tr wire:key="rm-{{ $item->id }}">
                                <td class="td-main">{{ $item->name }} <div class="text-[11px] text-pb-muted font-normal">{{ $item->category?->name ?? 'Tanpa kategori' }}</div></td>
                                <td class="!whitespace-normal text-[12px] text-pb-sub">
                                    @forelse ($item->recipeItems->whereNull('menu_variant_id') as $row)
                                        {{ $row->ingredient->name }} {{ Ingredient::qty($row->quantity) }}{{ $row->ingredient->base_unit->value }}@if (! $loop->last), @endif
                                    @empty
                                        <span class="text-pb-muted">Belum ada resep</span>
                                    @endforelse
                                    @if ($item->recipeItems->whereNotNull('menu_variant_id')->isNotEmpty())
                                        <span class="chip !text-[10px] !py-0 ml-1">+ per varian</span>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">{{ Money::format($price) }}</td>
                                <td class="text-right whitespace-nowrap">{{ $cost ? Money::format($cost) : '—' }}</td>
                                <td class="text-right">
                                    @if ($pct !== null)
                                        <span @class(['font-semibold', 'trend-up' => $pct <= 35, 'trend-down' => $pct > 45])>{{ number_format($pct, 1, ',', '.') }}%</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-right"><button type="button" wire:click="editRecipe('menu', {{ $item->id }})" class="btn-ghost font-semibold"><i class="fa-solid fa-book-open mr-1"></i>Resep</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center !py-8">Belum ada menu.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="px-5 py-3 border-t border-pb-border text-[12px] text-pb-muted">Food cost = biaya bahan ÷ harga jual. Hijau ≤ 35%, merah &gt; 45%. Biaya muncul setelah bahan punya HPP (dari pembelian / penyesuaian).</p>
        </div>
    @else
        <div class="card overflow-hidden animate-fadeUp">
            <table class="tbl">
                <thead><tr><th>Modifier</th><th>Tambahan bahan</th><th></th></tr></thead>
                <tbody>
                    @forelse ($this->modifiers as $modifier)
                        <tr wire:key="rmod-{{ $modifier->id }}">
                            <td class="td-main">{{ $modifier->name }} <div class="text-[11px] text-pb-muted font-normal">{{ $modifier->group->name }}</div></td>
                            <td class="!whitespace-normal text-[12px] text-pb-sub">
                                @forelse ($modifier->recipeItems as $row)
                                    {{ $row->ingredient->name }} {{ Ingredient::qty($row->quantity) }}{{ $row->ingredient->base_unit->value }}@if (! $loop->last), @endif
                                @empty
                                    <span class="text-pb-muted">—</span>
                                @endforelse
                            </td>
                            <td class="text-right"><button type="button" wire:click="editRecipe('modifier', {{ $modifier->id }})" class="btn-ghost font-semibold"><i class="fa-solid fa-book-open mr-1"></i>Resep</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center !py-8">Belum ada modifier.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ── Form bahan baku ── --}}
    <x-modal name="ingredient-form" maxWidth="lg" focusable>
        <form wire:submit="saveIngredient">
            <div class="px-6 py-5 border-b border-pb-border font-display text-base font-bold text-pb-text">{{ $ingredientId ? 'Ubah Bahan' : 'Tambah Bahan Baku' }}</div>
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <x-input-label for="name" value="Nama bahan" />
                    <x-text-input wire:model="name" id="name" placeholder="mis. Susu UHT" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="sku" value="SKU (opsional)" />
                    <x-text-input wire:model="sku" id="sku" />
                    <x-input-error :messages="$errors->get('sku')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="baseUnit" value="Satuan dasar" />
                    <select wire:model="baseUnit" id="baseUnit" class="f-input">
                        @foreach (StockUnit::cases() as $unit)
                            <option value="{{ $unit->value }}">{{ $unit->label() }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('baseUnit')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="purchaseUnit" value="Satuan beli (opsional)" />
                    <x-text-input wire:model="purchaseUnit" id="purchaseUnit" placeholder="mis. liter, kg, dus" />
                    <x-input-error :messages="$errors->get('purchaseUnit')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="purchaseUnitSize" value="Isi per satuan beli" />
                    <x-text-input wire:model="purchaseUnitSize" id="purchaseUnitSize" type="number" step="0.001" min="0" placeholder="mis. 1000" />
                    <x-input-error :messages="$errors->get('purchaseUnitSize')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="minStock" value="Stok minimum (satuan dasar)" />
                    <x-text-input wire:model="minStock" id="minStock" type="number" step="0.001" min="0" />
                    <x-input-error :messages="$errors->get('minStock')" class="mt-1.5" />
                </div>
                <div class="flex items-end">
                    <x-toggle wire:model="isActive" label="Aktif" />
                </div>
                <p class="f-hint col-span-2 !mt-0">Contoh: Susu UHT, satuan dasar ml, satuan beli "liter" berisi 1000. Resep & stok selalu memakai satuan dasar.</p>
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="saveIngredient"><i class="fa-solid fa-check"></i>Simpan</x-primary-button>
            </div>
        </form>
    </x-modal>

    {{-- ── Editor resep ── --}}
    <x-modal name="recipe-form" maxWidth="2xl">
        @php
            $owner = $recipeOwnerId ? ($recipeOwner === 'menu' ? $this->menuItems->firstWhere('id', $recipeOwnerId) : $this->modifiers->firstWhere('id', $recipeOwnerId)) : null;
            $variants = $recipeOwner === 'menu' ? ($owner?->variants ?? collect()) : collect();
            $allIngredients = auth()->user()->activeBusiness()->ingredients()->where('is_active', true)->get();
        @endphp
        <form wire:submit="saveRecipe">
            <div class="px-6 py-5 border-b border-pb-border">
                <div class="font-display text-base font-bold text-pb-text">Resep {{ $owner?->name }}</div>
                <div class="text-xs text-pb-muted mt-px">
                    {{ $recipeOwner === 'menu' ? 'Bahan per 1 porsi. Baris "Semua varian" selalu dipakai; baris varian ditambahkan bila varian itu dipilih.' : 'Bahan tambahan bila modifier ini dipilih (per porsi).' }}
                </div>
            </div>
            <div class="px-6 py-5 flex flex-col gap-2 max-h-[60vh] overflow-y-auto">
                @foreach ($recipeRows as $i => $row)
                    <div @class(['grid gap-2 items-start', 'grid-cols-[1fr_120px_150px_auto]' => $variants->isNotEmpty(), 'grid-cols-[1fr_140px_auto]' => $variants->isEmpty()]) wire:key="rr-{{ $i }}">
                        <div>
                            <select wire:model="recipeRows.{{ $i }}.ingredient_id" class="f-input" aria-label="Bahan">
                                <option value="">— Pilih bahan —</option>
                                @foreach ($allIngredients as $ingredient)
                                    <option value="{{ $ingredient->id }}">{{ $ingredient->name }} ({{ $ingredient->base_unit->value }})</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('recipeRows.'.$i.'.ingredient_id')" class="mt-1" />
                        </div>
                        <div>
                            <input type="number" step="0.001" min="0" wire:model="recipeRows.{{ $i }}.quantity" class="f-input" placeholder="Jumlah" aria-label="Jumlah">
                            <x-input-error :messages="$errors->get('recipeRows.'.$i.'.quantity')" class="mt-1" />
                        </div>
                        @if ($variants->isNotEmpty())
                            <select wire:model="recipeRows.{{ $i }}.variant_id" class="f-input" aria-label="Berlaku untuk">
                                <option value="">Semua varian</option>
                                @foreach ($variants as $variant)
                                    <option value="{{ $variant->id }}">+ {{ $variant->name }}</option>
                                @endforeach
                            </select>
                        @endif
                        <button type="button" wire:click="removeRecipeRow({{ $i }})" class="icon-btn !w-9 !h-9" aria-label="Hapus baris"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                @endforeach
                <div><button type="button" wire:click="addRecipeRow" class="btn-ghost font-semibold"><i class="fa-solid fa-plus mr-1"></i>Tambah bahan</button></div>
                @if ($allIngredients->isEmpty())
                    <p class="f-hint">Belum ada bahan baku aktif. Tambahkan bahan di tab "Bahan Baku" terlebih dahulu.</p>
                @endif
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="saveRecipe"><i class="fa-solid fa-check"></i>Simpan Resep</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
