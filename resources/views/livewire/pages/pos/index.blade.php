<?php

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\ServiceType;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Support\Money;
use App\Support\Pos\CartLine;
use App\Support\Pos\CartResolver;
use App\Support\Pos\OrderTotals;
use App\Support\Pos\PlaceOrder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Kasir')] class extends Component
{
    public ?int $outletId = null;

    // Katalog
    public string $categoryId = '';
    public string $search = '';

    /**
     * Isi keranjang. Hanya menyimpan pilihan; harga selalu dihitung ulang dari server.
     *
     * @var list<array{key: string, menu_item_id: int, variant_id: ?int, modifier_ids: list<int>, quantity: int, notes: ?string}>
     */
    public array $cart = [];

    // Detail order
    public string $serviceType = '';
    public string $tableNumber = '';
    public string $customerName = '';
    public string $orderNotes = '';

    // Diskon (Supervisor ke atas)
    public string $discountType = '';
    public string $discountValue = '';
    public string $discountReason = '';

    // Pemilih item (varian / modifier / catatan)
    public ?int $pickerItemId = null;
    public ?string $pickerLineKey = null;
    public string $pickerVariantId = '';
    /** @var list<string> */
    public array $pickerModifierIds = [];
    public int $pickerQuantity = 1;
    public string $pickerNotes = '';

    /** @var list<array{method: string, amount: string, reference: string}> */
    public array $payments = [];

    /** Idempotency key per transaksi, mencegah order ganda bila tombol ditekan dua kali. */
    public string $checkoutToken = '';

    public ?int $lastOrderId = null;

    public function mount(): void
    {
        $outlet = auth()->user()->currentOutlet();

        if ($outlet && auth()->user()->can('usePos', $outlet)) {
            $this->outletId = $outlet->id;
            $this->serviceType = $outlet->service_types->first()?->value ?? '';
        }

        $this->checkoutToken = (string) Str::uuid();
    }

    /* ─────────────── Data ─────────────── */

    #[Computed]
    public function outlet(): ?Outlet
    {
        return $this->outletId ? Outlet::with('business')->find($this->outletId) : null;
    }

    private function posOutlet(): Outlet
    {
        $outlet = $this->outlet;
        abort_unless($outlet && auth()->user()->can('usePos', $outlet), 403);

        return $outlet;
    }

    #[Computed]
    public function categories()
    {
        return $this->outlet->business->menuCategories()->where('is_active', true)->get();
    }

    /** Menu aktif di bisnis; status habis per outlet ditampilkan, bukan disembunyikan. */
    #[Computed]
    public function menu()
    {
        $outlet = $this->outlet;

        return MenuItem::query()
            ->where('business_id', $outlet->business_id)
            ->where('is_active', true)
            ->with(['variants', 'modifierGroups.modifiers', 'outlets' => fn ($q) => $q->whereKey($outlet->id)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function visibleMenu()
    {
        return $this->menu
            ->when(is_numeric($this->categoryId), fn ($items) => $items->where('menu_category_id', (int) $this->categoryId))
            ->when($this->search !== '', fn ($items) => $items->filter(fn ($item) => Str::contains($item->name.' '.$item->sku, $this->search, ignoreCase: true)))
            ->values();
    }

    /**
     * Baris keranjang ber-harga, dikunci dengan `key` baris. Baris yang tidak valid
     * (mis. menu baru saja ditandai habis) bernilai string pesan galat.
     *
     * @return array<string, CartLine|string>
     */
    #[Computed]
    public function lines(): array
    {
        $resolver = app(CartResolver::class);
        $lines = [];

        foreach ($this->cart as $line) {
            try {
                $lines[$line['key']] = $resolver->resolve($this->outlet, [$line])[0];
            } catch (ValidationException $e) {
                $lines[$line['key']] = collect($e->errors())->flatten()->first();
            }
        }

        return $lines;
    }

    #[Computed]
    public function totals(): OrderTotals
    {
        $subtotal = collect($this->lines)->sum(fn ($line) => $line instanceof CartLine ? $line->lineTotal() : 0);
        $type = $this->canDiscount() ? DiscountType::tryFrom($this->discountType) : null;

        return OrderTotals::calculate($this->outlet, $subtotal, $type, (float) ($this->discountValue ?: 0));
    }

    #[Computed]
    public function pickerItem(): ?MenuItem
    {
        return $this->pickerItemId ? $this->menu->firstWhere('id', $this->pickerItemId) : null;
    }

    #[Computed]
    public function lastOrder(): ?Order
    {
        return $this->lastOrderId ? Order::with('payments')->find($this->lastOrderId) : null;
    }

    public function canDiscount(): bool
    {
        return auth()->user()->hasPermission(Permission::ApproveTransactions);
    }

    public function paidTotal(): int
    {
        return collect($this->payments)->sum(fn ($p) => Money::parse($p['amount']) ?? 0);
    }

    /* ─────────────── Keranjang ─────────────── */

    public function pick(int $itemId): void
    {
        $outlet = $this->posOutlet();
        $item = $this->menu->firstWhere('id', $itemId);

        if (! $item || ! $item->isAvailableAt($outlet)) {
            $this->dispatch('toast', type: 'error', message: 'Menu ini sedang tidak tersedia.');

            return;
        }

        // Menu sederhana langsung masuk keranjang (atau menambah jumlah baris yang sama).
        if ($item->variants->isEmpty() && $item->modifierGroups->isEmpty()) {
            $this->addLine(['menu_item_id' => $item->id, 'variant_id' => null, 'modifier_ids' => [], 'quantity' => 1, 'notes' => null]);

            return;
        }

        $this->openPicker($item);
    }

    public function editLine(string $key): void
    {
        $line = collect($this->cart)->firstWhere('key', $key);
        $item = $line ? $this->menu->firstWhere('id', $line['menu_item_id']) : null;

        if (! $item) {
            return;
        }

        $this->openPicker($item, $line);
    }

    private function openPicker(MenuItem $item, ?array $line = null): void
    {
        $this->resetValidation();
        $this->pickerItemId = $item->id;
        $this->pickerLineKey = $line['key'] ?? null;
        $this->pickerVariantId = (string) ($line['variant_id'] ?? $item->variants->first()?->id ?? '');
        $this->pickerModifierIds = array_map('strval', $line['modifier_ids'] ?? []);
        $this->pickerQuantity = $line['quantity'] ?? 1;
        $this->pickerNotes = (string) ($line['notes'] ?? '');

        $this->dispatch('open-modal', name: 'item-picker');
    }

    /** Pilih satu modifier; grup "pilih 1" berperilaku seperti radio. */
    public function toggleModifier(int $groupId, int $modifierId): void
    {
        $group = $this->pickerItem?->modifierGroups->firstWhere('id', $groupId);

        if (! $group) {
            return;
        }

        $id = (string) $modifierId;
        $groupIds = $group->modifiers->pluck('id')->map(fn ($v) => (string) $v)->all();

        if (in_array($id, $this->pickerModifierIds, true)) {
            $this->pickerModifierIds = array_values(array_diff($this->pickerModifierIds, [$id]));

            return;
        }

        if ($group->max_select === 1) {
            $this->pickerModifierIds = array_values(array_diff($this->pickerModifierIds, $groupIds));
        }

        $this->pickerModifierIds[] = $id;
    }

    public function confirmPick(): void
    {
        $outlet = $this->posOutlet();

        $line = [
            'key' => $this->pickerLineKey ?? (string) Str::uuid(),
            'menu_item_id' => (int) $this->pickerItemId,
            'variant_id' => $this->pickerVariantId !== '' ? (int) $this->pickerVariantId : null,
            'modifier_ids' => array_map('intval', $this->pickerModifierIds),
            'quantity' => max(1, min(999, $this->pickerQuantity)),
            'notes' => trim($this->pickerNotes) ?: null,
        ];

        try {
            app(CartResolver::class)->resolve($outlet, [$line], 'picker');
        } catch (ValidationException $e) {
            $this->addError('picker', collect($e->errors())->flatten()->first());

            return;
        }

        if ($this->pickerLineKey) {
            $this->cart = array_map(fn ($l) => $l['key'] === $this->pickerLineKey ? $line : $l, $this->cart);
        } else {
            $this->addLine($line);
        }

        $this->dispatch('close-modal', name: 'item-picker');
        $this->reset('pickerItemId', 'pickerLineKey', 'pickerVariantId', 'pickerModifierIds', 'pickerQuantity', 'pickerNotes');
    }

    /** Gabungkan dengan baris yang pilihannya sama persis. */
    private function addLine(array $line): void
    {
        $signature = fn (array $l) => $l['menu_item_id'].'|'.$l['variant_id'].'|'.implode(',', collect($l['modifier_ids'])->sort()->all()).'|'.$l['notes'];

        foreach ($this->cart as $i => $existing) {
            if ($signature($existing) === $signature($line)) {
                $this->cart[$i]['quantity'] = min(999, $existing['quantity'] + $line['quantity']);

                return;
            }
        }

        $this->cart[] = $line + ['key' => (string) Str::uuid()];
    }

    public function changeQuantity(string $key, int $delta): void
    {
        $this->cart = collect($this->cart)
            ->map(fn ($l) => $l['key'] === $key ? ['quantity' => max(0, min(999, $l['quantity'] + $delta))] + $l : $l)
            ->filter(fn ($l) => $l['quantity'] > 0)
            ->values()
            ->all();
    }

    public function removeLine(string $key): void
    {
        $this->cart = collect($this->cart)->reject(fn ($l) => $l['key'] === $key)->values()->all();
    }

    public function clearCart(): void
    {
        $this->reset('cart', 'tableNumber', 'customerName', 'orderNotes', 'discountType', 'discountValue', 'discountReason', 'payments');
        $this->resetValidation();
    }

    /* ─────────────── Pembayaran ─────────────── */

    public function openPayment(): void
    {
        $outlet = $this->posOutlet();
        $this->resetValidation();

        if ($this->cart === []) {
            $this->dispatch('toast', type: 'error', message: 'Keranjang masih kosong.');

            return;
        }

        if (collect($this->lines)->contains(fn ($line) => is_string($line))) {
            $this->dispatch('toast', type: 'error', message: 'Ada item yang tidak tersedia. Hapus atau ubah dulu.');

            return;
        }

        $method = $outlet->accepts(PaymentMethod::Cash) ? PaymentMethod::Cash : $outlet->payment_methods->first();
        $this->payments = [['method' => $method->value, 'amount' => (string) $this->totals->total, 'reference' => '']];

        $this->dispatch('open-modal', name: 'payment');
    }

    /** Tambah metode lain untuk sisa tagihan (split payment). */
    public function addPayment(): void
    {
        $remaining = max(0, $this->totals->total - $this->paidTotal());
        $used = array_column($this->payments, 'method');
        $method = $this->outlet->payment_methods->first(fn ($m) => ! in_array($m->value, $used, true)) ?? $this->outlet->payment_methods->first();

        $this->payments[] = ['method' => $method->value, 'amount' => (string) $remaining, 'reference' => ''];
    }

    public function removePayment(int $index): void
    {
        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);
        $this->balanceLastPayment();
    }

    /** Nominal baris lain diubah → sisa tagihan otomatis masuk ke baris pembayaran terakhir. */
    public function updatedPayments(mixed $value, string $key): void
    {
        [$index, $field] = explode('.', $key) + [null, null];

        if ($field === 'amount' && (int) $index < count($this->payments) - 1) {
            $this->balanceLastPayment();
        }
    }

    private function balanceLastPayment(): void
    {
        $last = count($this->payments) - 1;

        if ($last < 1) {
            return;
        }

        $others = collect($this->payments)->take($last)->sum(fn ($p) => Money::parse($p['amount']) ?? 0);
        $this->payments[$last]['amount'] = (string) max(0, $this->totals->total - $others);
    }

    public function setAmount(int $index, int $amount): void
    {
        if (isset($this->payments[$index])) {
            $this->payments[$index]['amount'] = (string) $amount;
        }
    }

    public function checkout(): void
    {
        $outlet = $this->posOutlet();

        $order = app(PlaceOrder::class)->handle(auth()->user(), $outlet, [
            'client_reference' => 'web-'.$this->checkoutToken,
            'service_type' => $this->serviceType,
            'table_number' => $outlet->usesTables() && $this->serviceType === ServiceType::DineIn->value ? ($this->tableNumber ?: null) : null,
            'customer_name' => $this->customerName ?: null,
            'notes' => $this->orderNotes ?: null,
            'discount_type' => $this->canDiscount() && $this->discountType !== '' ? $this->discountType : null,
            'discount_value' => $this->canDiscount() ? ($this->discountValue ?: 0) : 0,
            'discount_reason' => $this->discountReason ?: null,
            'items' => array_map(fn ($l) => [
                'menu_item_id' => $l['menu_item_id'],
                'variant_id' => $l['variant_id'],
                'modifier_ids' => $l['modifier_ids'],
                'quantity' => $l['quantity'],
                'notes' => $l['notes'],
            ], $this->cart),
            // Baris split yang bernilai 0 (tidak terpakai) diabaikan.
            'payments' => collect($this->payments)
                ->map(fn ($p) => [
                    'method' => $p['method'],
                    'amount' => Money::parse($p['amount']) ?? 0,
                    'reference' => $p['reference'] ?: null,
                ])
                ->filter(fn ($p, $i) => $p['amount'] > 0 || count($this->payments) === 1)
                ->values()
                ->all(),
        ]);

        $this->lastOrderId = $order->id;
        $this->clearCart();
        $this->checkoutToken = (string) Str::uuid();
        unset($this->lastOrder);

        $this->dispatch('close-modal', name: 'payment');
        $this->dispatch('open-modal', name: 'order-done');
    }
}; ?>

<div>
@if (! $this->outlet)
    <div class="card max-w-xl animate-fadeUp">
        <div class="card-body text-center py-10">
            <i class="fa-solid fa-store-slash text-3xl text-pb-muted mb-3"></i>
            <div class="font-display font-bold text-pb-text mb-1">Kasir belum bisa dipakai</div>
            <p class="text-[13px] text-pb-muted">Outlet aktif kamu nonaktif atau belum ditugaskan. Pilih outlet lain atau hubungi pemilik bisnis.</p>
        </div>
    </div>
@else
    @php
        $outlet = $this->outlet;
        $totals = $this->totals;
        $lines = $this->lines;
    @endphp

    <div class="two-col !items-start">
        {{-- ═════ Katalog menu ═════ --}}
        <div class="flex flex-col gap-4 min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative flex-1 min-w-[200px]">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-pb-muted text-xs"></i>
                    <input type="search" wire:model.live.debounce.250ms="search" class="f-input !pl-8" placeholder="Cari menu atau SKU…" aria-label="Cari menu">
                </div>
            </div>
            <div class="flex gap-2 overflow-x-auto pb-1 -mb-1">
                <button type="button" wire:click="$set('categoryId', '')" @class(['chip whitespace-nowrap cursor-pointer', 'a-bg-lt a-text a-border' => $categoryId === ''])>Semua</button>
                @foreach ($this->categories as $category)
                    <button type="button" wire:key="cat-{{ $category->id }}" wire:click="$set('categoryId', '{{ $category->id }}')"
                            @class(['chip whitespace-nowrap cursor-pointer', 'a-bg-lt a-text a-border' => $categoryId === (string) $category->id])>{{ $category->name }}</button>
                @endforeach
            </div>

            <div class="grid gap-3 grid-cols-2 sm:grid-cols-3 2xl:grid-cols-4">
                @forelse ($this->visibleMenu as $item)
                    @php $available = $item->isAvailableAt($outlet); @endphp
                    <button type="button" wire:key="menu-{{ $item->id }}" wire:click="pick({{ $item->id }})" @disabled(! $available)
                            class="card text-left overflow-hidden transition hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:translate-y-0">
                        <div class="aspect-[4/3] bg-pb-surface2 grid place-items-center relative">
                            @if ($item->imageUrl())
                                <img src="{{ $item->imageUrl() }}" alt="" class="w-full h-full object-cover" loading="lazy">
                            @else
                                <span class="font-display text-2xl font-bold text-pb-muted">{{ Str::upper(Str::substr($item->name, 0, 2)) }}</span>
                            @endif
                            @unless ($available)
                                <span class="badge badge-red absolute top-2 left-2">Habis</span>
                            @endunless
                            @if ($item->variants->isNotEmpty() || $item->modifierGroups->isNotEmpty())
                                <span class="chip !text-[10px] !py-0.5 absolute top-2 right-2"><i class="fa-solid fa-sliders"></i></span>
                            @endif
                        </div>
                        <div class="px-3 py-2.5">
                            <div class="text-[13px] font-semibold text-pb-text leading-snug line-clamp-2">{{ $item->name }}</div>
                            <div class="text-[12.5px] a-text font-bold mt-0.5">
                                {{ $item->variants->isNotEmpty() ? 'mulai ' : '' }}{{ Money::format(max(0, $item->priceAt($outlet) + ($item->variants->min('price_adjustment') ?? 0))) }}
                            </div>
                        </div>
                    </button>
                @empty
                    <div class="col-span-full card"><div class="card-body text-center text-pb-muted text-[13px]">
                        @if ($this->menu->isEmpty())
                            Belum ada menu.
                            @can('menu.manage')<a href="{{ route('menu.items.create') }}" wire:navigate class="link">Tambah menu</a>@endcan
                        @else
                            Tidak ada menu yang cocok.
                        @endif
                    </div></div>
                @endforelse
            </div>
        </div>

        {{-- ═════ Keranjang ═════ --}}
        <div id="pos-cart" class="card min-[1101px]:sticky min-[1101px]:top-[84px] flex flex-col min-[1101px]:max-h-[calc(100vh-110px)] scroll-mt-24">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-basket-shopping"></i>Pesanan</div>
                @if ($cart !== [])
                    <button type="button" wire:click="clearCart" wire:confirm="Kosongkan pesanan?" class="btn-ghost text-[12px]" style="color:#f87171">Kosongkan</button>
                @endif
            </div>

            <div class="px-5 pt-4 flex flex-col gap-3">
                <div class="grid gap-2" style="grid-template-columns: repeat({{ $outlet->service_types->count() }}, minmax(0, 1fr))">
                    @foreach ($outlet->service_types as $type)
                        <label class="choice-card !p-2.5 !gap-2 justify-center items-center text-[12.5px] font-semibold">
                            <input type="radio" wire:model.live="serviceType" value="{{ $type->value }}" class="sr-only">
                            <i class="fa-solid {{ $type->icon() }} a-text"></i>{{ $type->label() }}
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('service_type')" />
                <div class="grid grid-cols-2 gap-2">
                    @if ($outlet->usesTables() && $serviceType === 'dine_in')
                        <input type="text" wire:model="tableNumber" class="f-input !py-2 !text-[12.5px]" placeholder="No. meja" aria-label="Nomor meja">
                    @endif
                    <input type="text" wire:model="customerName" @class(['f-input !py-2 !text-[12.5px]', 'col-span-2' => ! ($outlet->usesTables() && $serviceType === 'dine_in')])
                           placeholder="Nama pelanggan (opsional)" aria-label="Nama pelanggan">
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-5 py-3 flex flex-col divide-y divide-pb-border min-h-[120px]">
                @forelse ($cart as $line)
                    @php $resolved = $lines[$line['key']] ?? null; @endphp
                    <div class="py-2.5 flex gap-3" wire:key="line-{{ $line['key'] }}">
                        <div class="flex-1 min-w-0">
                            @if ($resolved instanceof CartLine)
                                <button type="button" wire:click="editLine('{{ $line['key'] }}')" class="text-left text-[13px] font-semibold text-pb-text hover:a-text">{{ $resolved->displayName() }}</button>
                                @if ($resolved->modifiers->isNotEmpty())
                                    <div class="text-[11.5px] text-pb-muted">+ {{ $resolved->modifierSummary() }}</div>
                                @endif
                                @if ($resolved->notes)
                                    <div class="text-[11.5px] text-pb-muted italic">“{{ $resolved->notes }}”</div>
                                @endif
                                <div class="text-[12px] text-pb-sub mt-0.5">{{ Money::format($resolved->unitPrice) }}</div>
                            @else
                                <div class="text-[12.5px] trend-down">{{ $resolved }}</div>
                            @endif
                        </div>
                        <div class="flex flex-col items-end gap-1.5 shrink-0">
                            <div class="flex items-center gap-1">
                                <button type="button" wire:click="changeQuantity('{{ $line['key'] }}', -1)" class="icon-btn !w-7 !h-7" aria-label="Kurangi"><i class="fa-solid fa-minus text-[10px]"></i></button>
                                <span class="w-7 text-center text-[13px] font-bold text-pb-text">{{ $line['quantity'] }}</span>
                                <button type="button" wire:click="changeQuantity('{{ $line['key'] }}', 1)" class="icon-btn !w-7 !h-7" aria-label="Tambah"><i class="fa-solid fa-plus text-[10px]"></i></button>
                            </div>
                            <div class="text-[13px] font-bold text-pb-text">{{ $resolved instanceof CartLine ? Money::format($resolved->lineTotal()) : '—' }}</div>
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-[12.5px] text-pb-muted">
                        <i class="fa-solid fa-hand-pointer text-xl mb-2 block"></i>Ketuk menu untuk menambahkan.
                    </div>
                @endforelse
            </div>
            <x-input-error :messages="collect($errors->getMessages())->filter(fn ($v, $k) => str_starts_with($k, 'items'))->flatten()->all()" class="px-5" />

            <div class="px-5 py-4 border-t border-pb-border flex flex-col gap-2 text-[13px]">
                @if ($this->canDiscount() && $cart !== [])
                    <div x-data="{ open: @js($discountType !== '') }">
                        <button type="button" x-show="! open" x-on:click="open = true" class="btn-ghost text-[12px] !px-0"><i class="fa-solid fa-tag mr-1"></i>Tambah diskon</button>
                        <div x-show="open" x-cloak class="grid grid-cols-[110px_1fr] gap-2 mb-1">
                            <select wire:model.live="discountType" class="f-input !py-1.5 !text-[12px]" aria-label="Jenis diskon">
                                <option value="">Tanpa diskon</option>
                                @foreach (DiscountType::cases() as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                            <input type="number" wire:model.live.debounce.400ms="discountValue" min="0" class="f-input !py-1.5 !text-[12px]" placeholder="Nilai diskon" aria-label="Nilai diskon" @disabled($discountType === '')>
                            <input type="text" wire:model="discountReason" class="f-input !py-1.5 !text-[12px] col-span-2" placeholder="Alasan diskon (dicatat)" aria-label="Alasan diskon">
                        </div>
                        <x-input-error :messages="$errors->get('discount_value')" />
                    </div>
                @endif

                <div class="flex justify-between text-pb-sub"><span>Subtotal</span><span>{{ Money::format($totals->subtotal) }}</span></div>
                @if ($totals->discount > 0)
                    <div class="flex justify-between text-pb-sub"><span>Diskon</span><span>-{{ Money::format($totals->discount) }}</span></div>
                @endif
                @if ($totals->serviceCharge > 0)
                    <div class="flex justify-between text-pb-sub"><span>Service {{ (float) $totals->serviceChargeRate }}%</span><span>{{ Money::format($totals->serviceCharge) }}</span></div>
                @endif
                @if ($totals->tax > 0)
                    <div class="flex justify-between text-pb-sub">
                        <span>{{ $totals->pricesIncludeTax ? 'Termasuk pajak' : 'Pajak' }} {{ (float) $totals->taxRate }}%</span>
                        <span>{{ Money::format($totals->tax) }}</span>
                    </div>
                @endif
                <div class="flex justify-between font-display text-lg font-bold text-pb-text mt-1"><span>Total</span><span>{{ Money::format($totals->total) }}</span></div>

                <button type="button" wire:click="openPayment" class="btn btn-primary w-full !py-3 mt-1" @disabled($cart === [])>
                    <i class="fa-solid fa-money-bill-wave"></i>Bayar {{ $cart !== [] ? Money::format($totals->total) : '' }}
                </button>
            </div>
        </div>
    </div>

    {{-- Layar sempit: keranjang ada di bawah katalog, jadi tampilkan pintasan ke keranjang. --}}
    @if ($cart !== [])
        <div class="min-[1101px]:hidden h-16"></div>
        <a href="#pos-cart" class="min-[1101px]:hidden fixed bottom-4 inset-x-4 z-30 btn btn-primary !py-3 shadow-lg justify-between">
            <span><i class="fa-solid fa-basket-shopping mr-1.5"></i>{{ collect($cart)->sum('quantity') }} item</span>
            <span>Lihat pesanan · {{ Money::format($totals->total) }}</span>
        </a>
    @endif

    {{-- ═════ Pemilih varian / modifier ═════ --}}
    <x-modal name="item-picker" maxWidth="lg">
        @if ($item = $this->pickerItem)
            <form wire:submit="confirmPick">
                <div class="px-6 py-5 border-b border-pb-border flex items-center justify-between">
                    <div>
                        <div class="font-display text-base font-bold text-pb-text">{{ $item->name }}</div>
                        <div class="text-xs text-pb-muted mt-px">{{ Money::format($item->priceAt($outlet)) }}</div>
                    </div>
                    <button type="button" x-on:click="$dispatch('close')" class="icon-btn !w-8 !h-8" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="px-6 py-5 flex flex-col gap-5 max-h-[60vh] overflow-y-auto">
                    @if ($item->variants->isNotEmpty())
                        <div>
                            <div class="f-label">Varian <span class="text-pb-muted font-normal">· wajib pilih 1</span></div>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ($item->variants as $variant)
                                    <label class="choice-card !p-3 !items-center" wire:key="pv-{{ $variant->id }}">
                                        <input type="radio" wire:model="pickerVariantId" value="{{ $variant->id }}">
                                        <span class="flex-1 text-[13px] font-semibold text-pb-text">{{ $variant->name }}</span>
                                        <span class="text-[12px] text-pb-muted">{{ Money::format(max(0, $item->priceAt($outlet) + $variant->price_adjustment)) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @foreach ($item->modifierGroups as $group)
                        <div wire:key="pg-{{ $group->id }}">
                            <div class="f-label">{{ $group->name }} <span class="text-pb-muted font-normal">· {{ $group->ruleLabel() }}</span></div>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ($group->modifiers as $modifier)
                                    @php $selected = in_array((string) $modifier->id, $pickerModifierIds, true); @endphp
                                    <button type="button" wire:key="pm-{{ $modifier->id }}" wire:click="toggleModifier({{ $group->id }}, {{ $modifier->id }})" @disabled(! $modifier->is_available)
                                            @class(['choice-card !p-3 !items-center text-left disabled:opacity-50', 'a-border !bg-[var(--ac-lt2)]' => $selected])>
                                        <i @class(['fa-solid', 'fa-circle-check a-text' => $selected, 'fa-circle text-pb-muted' => ! $selected])></i>
                                        <span class="flex-1 text-[13px] text-pb-text">{{ $modifier->name }}</span>
                                        <span class="text-[12px] text-pb-muted">{{ $modifier->is_available ? ($modifier->price ? '+'.Money::plain($modifier->price) : '') : 'Habis' }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <div class="grid grid-cols-[auto_1fr] gap-4 items-end">
                        <div>
                            <div class="f-label">Jumlah</div>
                            <div class="flex items-center gap-1.5">
                                <button type="button" wire:click="$set('pickerQuantity', {{ max(1, $pickerQuantity - 1) }})" class="icon-btn !w-9 !h-9" aria-label="Kurangi"><i class="fa-solid fa-minus text-xs"></i></button>
                                <input type="number" wire:model="pickerQuantity" min="1" max="999" class="f-input !w-16 text-center !px-1" aria-label="Jumlah">
                                <button type="button" wire:click="$set('pickerQuantity', {{ min(999, $pickerQuantity + 1) }})" class="icon-btn !w-9 !h-9" aria-label="Tambah"><i class="fa-solid fa-plus text-xs"></i></button>
                            </div>
                        </div>
                        <div>
                            <div class="f-label">Catatan item</div>
                            <input type="text" wire:model="pickerNotes" maxlength="255" class="f-input" placeholder="mis. tanpa es, pedas sedang">
                        </div>
                    </div>
                    <x-input-error :messages="$errors->get('picker')" />
                </div>
                <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled" wire:target="confirmPick">
                        <i class="fa-solid fa-check"></i>{{ $pickerLineKey ? 'Simpan' : 'Tambahkan' }}
                    </x-primary-button>
                </div>
            </form>
        @endif
    </x-modal>

    {{-- ═════ Pembayaran ═════ --}}
    <x-modal name="payment" maxWidth="lg">
        @php
            $paid = $this->paidTotal();
            $remaining = $totals->total - $paid;
        @endphp
        <form wire:submit="checkout">
            <div class="px-6 py-5 border-b border-pb-border flex items-center justify-between">
                <div>
                    <div class="font-display text-base font-bold text-pb-text">Pembayaran</div>
                    <div class="text-xs text-pb-muted mt-px">Total tagihan <b class="text-pb-text">{{ Money::format($totals->total) }}</b></div>
                </div>
                <button type="button" x-on:click="$dispatch('close')" class="icon-btn !w-8 !h-8" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="px-6 py-5 flex flex-col gap-4 max-h-[60vh] overflow-y-auto">
                @foreach ($payments as $i => $payment)
                    <div class="rounded-xl border border-pb-border p-3 flex flex-col gap-2.5" wire:key="pay-{{ $i }}">
                        <div class="grid grid-cols-[1fr_1fr_auto] gap-2">
                            <select wire:model.live="payments.{{ $i }}.method" class="f-input" aria-label="Metode pembayaran">
                                @foreach ($outlet->payment_methods as $method)
                                    <option value="{{ $method->value }}">{{ $method->label() }}</option>
                                @endforeach
                            </select>
                            <input type="number" wire:model.live.debounce.300ms="payments.{{ $i }}.amount" min="0" class="f-input font-bold" aria-label="Nominal">
                            @if (count($payments) > 1)
                                <button type="button" wire:click="removePayment({{ $i }})" class="icon-btn !w-10 !h-10" aria-label="Hapus pembayaran"><i class="fa-solid fa-xmark"></i></button>
                            @endif
                        </div>
                        @if ($payment['method'] === PaymentMethod::Cash->value)
                            @php $due = max(0, $totals->total - ($paid - (Money::parse($payment['amount']) ?? 0))); @endphp
                            <div class="flex flex-wrap gap-1.5">
                                <button type="button" wire:click="setAmount({{ $i }}, {{ $due }})" class="chip cursor-pointer">Uang pas</button>
                                {{-- Nominal uang yang lazim diterima: dibulatkan ke atas ke pecahan 5rb / 10rb / 50rb / 100rb. --}}
                                @foreach (collect([5000, 10000, 50000, 100000])->map(fn ($n) => (int) (ceil(max($due, 1) / $n) * $n))->filter(fn ($n) => $n > $due)->unique()->take(3) as $note)
                                    <button type="button" wire:click="setAmount({{ $i }}, {{ $note }})" class="chip cursor-pointer">{{ Money::format($note) }}</button>
                                @endforeach
                            </div>
                        @else
                            <input type="text" wire:model="payments.{{ $i }}.reference" maxlength="100" class="f-input !py-1.5 !text-[12.5px]" placeholder="No. referensi / approval (opsional)" aria-label="Referensi">
                        @endif
                        <x-input-error :messages="array_merge($errors->get('payments.'.$i.'.method'), $errors->get('payments.'.$i.'.amount'))" />
                    </div>
                @endforeach

                @if (count($payments) < 5)
                    <button type="button" wire:click="addPayment" class="btn-ghost font-semibold self-start"><i class="fa-solid fa-plus mr-1"></i>Bayar dengan metode lain (split)</button>
                @endif

                <div class="rounded-xl bg-pb-surface2 p-4 flex flex-col gap-1.5 text-[13px]">
                    <div class="flex justify-between text-pb-sub"><span>Diterima</span><span>{{ Money::format($paid) }}</span></div>
                    @if ($remaining > 0)
                        <div class="flex justify-between font-bold trend-down"><span>Kurang</span><span>{{ Money::format($remaining) }}</span></div>
                    @else
                        <div class="flex justify-between font-display text-lg font-bold text-pb-text"><span>Kembalian</span><span>{{ Money::format(-$remaining) }}</span></div>
                    @endif
                </div>
                <x-input-error :messages="$errors->get('payments')" />
                <x-input-error :messages="collect($errors->getMessages())->except(['payments'])->filter(fn ($v, $k) => ! str_starts_with($k, 'payments.'))->flatten()->all()" />
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Kembali</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="checkout" :disabled="$remaining > 0">
                    <i class="fa-solid fa-check"></i>Selesaikan
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    {{-- ═════ Transaksi selesai ═════ --}}
    <x-modal name="order-done" maxWidth="md">
        @if ($order = $this->lastOrder)
            <div class="px-6 py-7 text-center flex flex-col items-center gap-2">
                <div class="w-14 h-14 rounded-full grid place-items-center" style="background:rgba(52,211,153,.15);color:#34d399"><i class="fa-solid fa-check text-2xl"></i></div>
                <div class="font-display text-lg font-bold text-pb-text">Transaksi berhasil</div>
                <div class="text-[13px] text-pb-muted">{{ $order->number }} · {{ Money::format($order->total) }}</div>
                @if ($order->change_amount > 0)
                    <div class="mt-2 rounded-xl bg-pb-surface2 px-5 py-3">
                        <div class="text-[11px] text-pb-muted uppercase tracking-wider">Kembalian</div>
                        <div class="font-display text-2xl font-bold text-pb-text">{{ Money::format($order->change_amount) }}</div>
                    </div>
                @endif
            </div>
            <div class="px-6 pb-6 grid grid-cols-2 gap-2.5">
                <a href="{{ route('receipts.show', ['token' => $order->receipt_token, 'print' => 1]) }}" target="_blank" class="btn btn-secondary"><i class="fa-solid fa-print"></i>Cetak Struk</a>
                <a href="{{ $order->receiptUrl() }}" target="_blank" class="btn btn-secondary"><i class="fa-solid fa-qrcode"></i>Struk Digital</a>
                <button type="button" x-on:click="$dispatch('close')" class="btn btn-primary col-span-2"><i class="fa-solid fa-plus"></i>Pesanan Baru</button>
            </div>
        @endif
    </x-modal>
@endif
</div>
