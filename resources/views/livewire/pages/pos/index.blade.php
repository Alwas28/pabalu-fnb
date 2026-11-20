<?php

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\ServiceType;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Support\Money;
use App\Support\Pos\AddOrderItems;
use App\Support\Pos\CancelOrder;
use App\Support\Pos\CartLine;
use App\Support\Pos\CartResolver;
use App\Support\Pos\OrderTotals;
use App\Support\Pos\MergeOrders;
use App\Support\Pos\MoveOrderTable;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\SettleOrder;
use App\Support\Pos\ShiftManager;
use App\Support\Pos\VoidOrderItem;
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
    /** id meja terpilih (outlet yang memakai meja). */
    public string $tableId = '';
    public string $customerName = '';
    /** Pelanggan terdaftar yang dipilih (opsional). */
    public ?int $customerId = null;
    public string $newCustomerPhone = '';

    // Delivery
    public string $deliveryChannel = '';
    public string $deliveryReference = '';
    public string $customerPhone = '';
    public string $deliveryAddress = '';
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

    /** Open bill yang sedang dibuka di kasir (tambah item / pelunasan). */
    public ?int $activeOrderId = null;

    /** Pemilih meja: 'select' untuk pesanan baru, 'move' untuk memindahkan open bill. */
    public string $tablePickerMode = 'select';

    // Buka shift dari layar kasir
    public string $openingCash = '';

    /** Tiket dapur terakhir yang perlu dicetak (outlet mode cetak tiket): [order id, batch]. */
    public ?array $pendingTicket = null;

    public function mount(): void
    {
        $outlet = auth()->user()->currentOutlet();

        if ($outlet && auth()->user()->can('usePos', $outlet)) {
            $this->outletId = $outlet->id;
            $this->serviceType = $outlet->service_types->first()?->value ?? '';

            // Dibuka dari detail pesanan: langsung muat open bill tersebut.
            $orderId = request()->integer('order');
            if ($orderId && Order::where('outlet_id', $outlet->id)->open()->whereKey($orderId)->exists()) {
                $this->loadOrder($orderId);
            }
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
        $subtotal = collect($this->lines)->sum(fn ($line) => $line instanceof CartLine ? $line->lineTotal() : 0)
            + (int) $this->activeOrder?->items->sum('line_total');
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

    /** Pelanggan terdaftar yang cocok dengan ketikan nama / HP. */
    #[Computed]
    public function customerMatches()
    {
        $term = trim($this->customerName);

        if ($this->customerId || mb_strlen($term) < 2) {
            return collect();
        }

        return Customer::applySearch(Customer::where('business_id', $this->outlet->business_id), $term)->orderBy('name')->limit(5)->get();
    }

    #[Computed]
    public function selectedCustomer(): ?Customer
    {
        return $this->customerId ? Customer::where('business_id', $this->outlet->business_id)->find($this->customerId) : null;
    }

    public function selectCustomer(int $id): void
    {
        $customer = Customer::where('business_id', $this->posOutlet()->business_id)->find($id);
        abort_unless($customer, 404);

        $this->customerId = $customer->id;
        $this->customerName = $customer->name;
        unset($this->selectedCustomer, $this->customerMatches);
    }

    public function clearCustomer(): void
    {
        $this->reset('customerId', 'customerName');
        unset($this->selectedCustomer);
    }

    /** Simpan ketikan nama sebagai pelanggan baru (nomor HP opsional). */
    public function createCustomer(): void
    {
        $outlet = $this->posOutlet();
        $phone = Customer::normalizePhone($this->newCustomerPhone);

        $data = \Illuminate\Support\Facades\Validator::make(
            ['name' => trim($this->customerName), 'phone' => $phone],
            \Illuminate\Support\Arr::only(Customer::rules($outlet->business_id), ['name', 'phone']),
            ['phone.unique' => 'Nomor HP sudah terdaftar — cari pelanggannya.'],
        )->validate();

        $customer = $outlet->business->customers()->create($data);
        $this->reset('newCustomerPhone');
        $this->selectCustomer($customer->id);
        $this->dispatch('toast', type: 'success', message: 'Pelanggan '.$customer->name.' ditambahkan');
    }

    #[Computed]
    public function shift(): ?CashShift
    {
        return $this->outlet ? CashShift::currentFor(auth()->user(), $this->outlet) : null;
    }

    #[Computed]
    public function tables()
    {
        if (! $this->outlet?->usesTables()) {
            return collect();
        }

        return $this->outlet->diningTables()
            ->where('is_active', true)
            ->with(['openOrders' => fn ($q) => $q->select('id', 'dining_table_id', 'number', 'total')])
            ->get();
    }

    #[Computed]
    public function selectedTable(): ?DiningTable
    {
        return $this->tableId !== '' ? $this->tables->firstWhere('id', (int) $this->tableId) : null;
    }

    /** Open bill di outlet ini (semua kasir), terbaru dulu. */
    #[Computed]
    public function openOrders()
    {
        return Order::where('outlet_id', $this->outletId)->open()->with('cashier')->withCount('items')->latest('placed_at')->get();
    }

    #[Computed]
    public function activeOrder(): ?Order
    {
        if (! $this->activeOrderId) {
            return null;
        }

        $order = Order::with('items.modifiers')->where('outlet_id', $this->outletId)->find($this->activeOrderId);

        return $order?->isOpen() ? $order : null;
    }

    /** Pesanan baru dine-in boleh disimpan sebagai open bill. */
    public function canPayLater(): bool
    {
        return ! $this->activeOrderId
            && $this->outlet?->allowsPayLater()
            && $this->serviceType === ServiceType::DineIn->value;
    }

    public function canDiscount(): bool
    {
        return auth()->user()->hasPermission(Permission::ApproveTransactions);
    }

    public function paidTotal(): int
    {
        return collect($this->payments)->sum(fn ($p) => Money::parse($p['amount']) ?? 0);
    }

    /* ─────────────── Shift ─────────────── */

    public function openShift(): void
    {
        app(ShiftManager::class)->open(auth()->user(), $this->posOutlet(), [
            'opening_cash' => Money::parse($this->openingCash) ?? '',
        ]);

        $this->reset('openingCash');
        unset($this->shift);
        $this->dispatch('toast', type: 'success', message: 'Shift dibuka. Selamat bekerja!');
    }

    /* ─────────────── Meja & open bill ─────────────── */

    public function openTablePicker(string $mode = 'select'): void
    {
        $this->tablePickerMode = $mode === 'move' ? 'move' : 'select';
        unset($this->tables);
        $this->dispatch('open-modal', name: 'table-picker');
    }

    public function selectTable(int $tableId): void
    {
        $this->posOutlet();
        $table = $this->tables->firstWhere('id', $tableId);
        abort_unless($table, 404);

        if ($this->tablePickerMode === 'move' && $this->activeOrder) {
            app(MoveOrderTable::class)->handle(auth()->user(), $this->activeOrder, $table->id);
            unset($this->activeOrder, $this->tables, $this->openOrders);
            $this->dispatch('toast', type: 'success', message: 'Pesanan dipindah ke meja '.$table->name);
        }

        $this->tableId = (string) $table->id;
        $this->dispatch('close-modal', name: 'table-picker');
    }

    /** Kosongkan meja manual (tamu sudah pergi). Meja dengan open bill tidak bisa dikosongkan. */
    public function clearTable(int $tableId): void
    {
        $this->posOutlet();
        $table = $this->tables->firstWhere('id', $tableId);
        abort_unless($table, 404);

        if ($table->openOrders->isEmpty()) {
            $table->forceFill(['occupied_at' => null])->save();
            unset($this->tables);
        }
    }

    public function loadOrder(int $orderId): void
    {
        $this->posOutlet();
        $order = Order::where('outlet_id', $this->outletId)->open()->find($orderId);
        abort_unless($order, 404);

        $this->clearCart();
        $this->activeOrderId = $order->id;
        $this->serviceType = $order->service_type->value;
        $this->tableId = (string) $order->dining_table_id;
        $this->customerName = (string) $order->customer_name;
        $this->customerId = $order->customer_id;
        unset($this->activeOrder);

        $this->dispatch('close-modal', name: 'open-orders');
        $this->dispatch('close-modal', name: 'table-picker');
    }

    public function leaveOrder(): void
    {
        $this->clearCart();
    }

    /** Simpan pesanan baru sebagai open bill, atau tambahkan isi keranjang ke open bill aktif. */
    public function saveOpenOrder(): void
    {
        $outlet = $this->posOutlet();

        if ($this->activeOrder) {
            $order = app(AddOrderItems::class)->handle(auth()->user(), $this->activeOrder, ['items' => $this->cartPayload()]);
            $message = 'Item ditambahkan ke '.$order->number;
        } else {
            $order = app(PlaceOrder::class)->handle(auth()->user(), $outlet, $this->orderPayload() + ['pay_later' => true]);
            $message = 'Pesanan '.$order->number.' disimpan'.($order->table_number ? ' · Meja '.$order->table_number : '').' — bayar nanti';
        }

        $this->rememberTicket($order);
        $this->clearCart();
        $this->checkoutToken = (string) Str::uuid();
        unset($this->openOrders, $this->tables);
        $this->dispatch('toast', type: 'success', message: $message);
    }

    /** Hapus / kurangi item open bill (Supervisor ke atas, wajib alasan). */
    public function voidItem(int $itemId, int $quantity, string $reason = ''): void
    {
        $this->posOutlet();
        $order = $this->activeOrder;
        abort_unless($order, 404);
        $item = $order->items->firstWhere('id', $itemId);
        abort_unless($item, 404);

        app(VoidOrderItem::class)->handle(auth()->user(), $order, $item, ['quantity' => $quantity, 'reason' => $reason]);

        unset($this->activeOrder, $this->openOrders);
        $this->dispatch('toast', type: 'success', message: $quantity.'× '.$item->displayName().' dihapus dari pesanan');
    }

    /** Gabungkan open bill lain ke open bill yang sedang dibuka. */
    public function mergeOrder(int $sourceId): void
    {
        $this->posOutlet();
        $target = $this->activeOrder;
        abort_unless($target, 404);
        $source = Order::where('outlet_id', $this->outletId)->open()->find($sourceId);
        abort_unless($source, 404);

        app(MergeOrders::class)->handle(auth()->user(), $target, $source);

        unset($this->activeOrder, $this->openOrders, $this->tables);
        $this->dispatch('close-modal', name: 'merge-orders');
        $this->dispatch('toast', type: 'success', message: $source->number.' digabung ke '.$target->number);
    }

    public function cancelOrder(string $reason = ''): void
    {
        $this->posOutlet();
        abort_unless($this->activeOrder, 404);
        $this->authorize('cancel', $this->activeOrder);

        $order = app(CancelOrder::class)->handle(auth()->user(), $this->activeOrder, ['reason' => $reason]);

        $this->clearCart();
        unset($this->openOrders, $this->tables);
        $this->dispatch('toast', type: 'success', message: 'Pesanan '.$order->number.' dibatalkan');
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
        $this->reset('cart', 'tableId', 'customerName', 'customerId', 'newCustomerPhone', 'deliveryChannel', 'deliveryReference', 'customerPhone', 'deliveryAddress', 'orderNotes', 'discountType', 'discountValue', 'discountReason', 'payments', 'activeOrderId');
        unset($this->activeOrder);
        $this->resetValidation();
    }

    /* ─────────────── Pembayaran ─────────────── */

    public function openPayment(): void
    {
        $outlet = $this->posOutlet();
        $this->resetValidation();

        if ($this->cart === [] && ! $this->activeOrder) {
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
        $payment = [
            'discount_type' => $this->canDiscount() && $this->discountType !== '' ? $this->discountType : null,
            'discount_value' => $this->canDiscount() ? ($this->discountValue ?: 0) : 0,
            'discount_reason' => $this->discountReason ?: null,
            // Baris split yang bernilai 0 (tidak terpakai) diabaikan.
            'payments' => collect($this->payments)
                ->map(fn ($p) => [
                    'method' => $p['method'],
                    'amount' => Money::parse($p['amount']) ?? 0,
                    'reference' => $p['reference'] ?: null,
                ])
                ->filter(fn ($p) => $p['amount'] > 0 || count($this->payments) === 1)
                ->values()
                ->all(),
        ];

        if ($order = $this->activeOrder) {
            // Pelunasan open bill; item baru di keranjang ditambahkan lebih dulu.
            if ($this->cart !== []) {
                $order = app(AddOrderItems::class)->handle(auth()->user(), $order, ['items' => $this->cartPayload()]);
            }

            $order = app(SettleOrder::class)->handle(auth()->user(), $order, $payment);
        } else {
            $order = app(PlaceOrder::class)->handle(auth()->user(), $outlet, $this->orderPayload() + $payment);
        }

        $this->lastOrderId = $order->id;
        $this->rememberTicket($order);
        $this->clearCart();
        $this->checkoutToken = (string) Str::uuid();
        unset($this->lastOrder, $this->openOrders, $this->tables);

        $this->dispatch('close-modal', name: 'payment');
        $this->dispatch('open-modal', name: 'order-done');
    }

    /**
     * Outlet tanpa layar dapur: siapkan tiket dapur untuk kiriman item terakhir.
     * Tidak ada tiket bila kiriman itu hanya berisi menu yang tidak perlu dapur (mis. air mineral).
     */
    private function rememberTicket(Order $order): void
    {
        $batch = (int) $order->items()->max('kitchen_batch');
        $hasKitchenItems = $order->items()->where('kitchen_batch', $batch)->where('sent_to_kitchen', true)->where('quantity', '>', 0)->exists();

        $this->pendingTicket = $this->outlet->usesKitchenDisplay() || ! $hasKitchenItems
            ? null
            : ['order' => $order->id, 'number' => $order->number, 'batch' => $batch];
    }

    /** @return array<string, mixed> */
    private function orderPayload(): array
    {
        $outlet = $this->outlet;
        $dineIn = $this->serviceType === ServiceType::DineIn->value;

        return [
            'client_reference' => 'web-'.$this->checkoutToken,
            'service_type' => $this->serviceType,
            'dining_table_id' => $outlet->usesTables() && $dineIn && $this->tableId !== '' ? (int) $this->tableId : null,
            'customer_id' => $this->customerId,
            'customer_phone' => $this->customerPhone ?: null,
            'delivery_channel' => $this->serviceType === ServiceType::Delivery->value ? ($this->deliveryChannel ?: null) : null,
            'delivery_reference' => $this->serviceType === ServiceType::Delivery->value ? ($this->deliveryReference ?: null) : null,
            'delivery_address' => $this->serviceType === ServiceType::Delivery->value ? ($this->deliveryAddress ?: null) : null,
            'customer_name' => $this->customerName ?: null,
            'notes' => $this->orderNotes ?: null,
            'items' => $this->cartPayload(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function cartPayload(): array
    {
        return array_map(fn ($l) => [
            'menu_item_id' => $l['menu_item_id'],
            'variant_id' => $l['variant_id'],
            'modifier_ids' => $l['modifier_ids'],
            'quantity' => $l['quantity'],
            'notes' => $l['notes'],
        ], $this->cart);
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
        $shift = $this->shift;
        $activeOrder = $this->activeOrder;
        $selectedTable = $this->selectedTable;
    @endphp

    @if (! $shift)
    <form wire:submit="openShift" class="card max-w-lg animate-fadeUp">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-door-open"></i>Buka Shift · {{ $outlet->name }}</div>
                <div class="section-desc">Shift wajib dibuka sebelum menerima transaksi. Hitung uang modal di laci kas.</div>
            </div>
        </div>
        <div class="card-body flex flex-col gap-4">
            <div>
                <x-input-label for="openingCash" value="Modal awal kas (Rp)" />
                <x-text-input wire:model="openingCash" id="openingCash" type="number" min="0" placeholder="mis. 200000" required autofocus />
                <x-input-error :messages="array_merge($errors->get('opening_cash'), $errors->get('shift'))" class="mt-1.5" />
            </div>
            <x-primary-button class="self-start" wire:loading.attr="disabled" wire:target="openShift"><i class="fa-solid fa-door-open"></i>Buka Shift &amp; Mulai</x-primary-button>
        </div>
    </form>
    @else

    <div class="flex flex-wrap items-center gap-2 mb-4 text-[12.5px]">
        <a href="{{ route('shifts.index') }}" wire:navigate class="chip"><span class="w-2 h-2 rounded-full bg-[#34d399] inline-block mr-1"></span>Shift sejak {{ $shift->opened_at->timezone($outlet->business->timezone)->format('H:i') }}</a>
        @if ($outlet->allowsPayLater() || $this->openOrders->isNotEmpty())
            <button type="button" x-data x-on:click="$dispatch('open-modal', 'open-orders')" class="chip cursor-pointer {{ $this->openOrders->isNotEmpty() ? 'a-bg-lt a-text a-border' : '' }}">
                <i class="fa-solid fa-file-invoice"></i>Pesanan terbuka ({{ $this->openOrders->count() }})
            </button>
        @endif
        @if ($outlet->usesTables())
            <button type="button" wire:click="openTablePicker('select')" class="chip cursor-pointer"><i class="fa-solid fa-chair"></i>Denah meja</button>
        @endif
        @if ($pendingTicket)
            <a href="{{ route('orders.kitchen-ticket', ['order' => $pendingTicket['order'], 'batch' => $pendingTicket['batch']]) }}" target="_blank"
               x-data x-on:click="$wire.set('pendingTicket', null)" class="chip cursor-pointer a-bg-lt a-text a-border">
                <i class="fa-solid fa-print"></i>Cetak tiket dapur {{ $pendingTicket['number'] }}{{ $pendingTicket['batch'] > 1 ? ' (tambahan)' : '' }}
            </a>
        @endif
        <x-input-error :messages="$errors->get('shift')" />
    </div>

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
                @if ($activeOrder)
                    <div class="min-w-0">
                        <div class="card-title"><i class="fa-solid fa-file-invoice"></i>{{ $activeOrder->number }}</div>
                        <div class="section-desc">Bayar nanti{{ $activeOrder->table_number ? ' · Meja '.$activeOrder->table_number : '' }}{{ $activeOrder->customer_name ? ' · '.$activeOrder->customer_name : '' }}</div>
                    </div>
                    <button type="button" wire:click="leaveOrder" class="btn-ghost text-[12px]"><i class="fa-solid fa-xmark mr-1"></i>Tutup</button>
                @else
                    <div class="card-title"><i class="fa-solid fa-basket-shopping"></i>Pesanan</div>
                    @if ($cart !== [])
                        <button type="button" wire:click="clearCart" wire:confirm="Kosongkan pesanan?" class="btn-ghost text-[12px]" style="color:#f87171">Kosongkan</button>
                    @endif
                @endif
            </div>

            <div class="px-5 pt-4 flex flex-col gap-3">
                @if ($activeOrder)
                    <div class="flex flex-wrap gap-1.5">
                        @if ($outlet->usesTables())
                            <button type="button" wire:click="openTablePicker('move')" class="btn-ghost text-[12px] font-semibold"><i class="fa-solid fa-right-left mr-1"></i>Pindah meja</button>
                        @endif
                        <a href="{{ route('orders.show', $activeOrder) }}" wire:navigate class="btn-ghost text-[12px] font-semibold"><i class="fa-solid fa-receipt mr-1"></i>Detail</a>
                        @if ($this->openOrders->count() > 1)
                            <button type="button" x-data x-on:click="$dispatch('open-modal', 'merge-orders')" class="btn-ghost text-[12px] font-semibold"><i class="fa-solid fa-object-group mr-1"></i>Gabung</button>
                        @endif
                        @can('cancel', $activeOrder)
                            <button type="button" x-data
                                    x-on:click="const r = prompt('Alasan pembatalan {{ $activeOrder->number }}:'); if (r !== null) $wire.cancelOrder(r)"
                                    class="btn-ghost text-[12px] font-semibold ml-auto" style="color:#f87171"><i class="fa-solid fa-ban mr-1"></i>Batalkan</button>
                        @endcan
                    </div>
                    <x-input-error :messages="array_merge($errors->get('reason'), $errors->get('order'), $errors->get('dining_table_id'))" />
                @else
                <div class="grid gap-2" style="grid-template-columns: repeat({{ $outlet->service_types->count() }}, minmax(0, 1fr))">
                    @foreach ($outlet->service_types as $type)
                        <label class="choice-card !p-2.5 !gap-2 justify-center items-center text-[12.5px] font-semibold">
                            <input type="radio" wire:model.live="serviceType" value="{{ $type->value }}" class="sr-only">
                            <i class="fa-solid {{ $type->icon() }} a-text"></i>{{ $type->label() }}
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('service_type')" />
                @if ($serviceType === 'delivery')
                    <div class="grid grid-cols-2 gap-2">
                        <select wire:model.live="deliveryChannel" class="f-input !py-2 !text-[12.5px]" aria-label="Kanal delivery">
                            <option value="">— Kanal delivery —</option>
                            @foreach (\App\Enums\DeliveryChannel::cases() as $channel)
                                <option value="{{ $channel->value }}">{{ $channel->label() }}</option>
                            @endforeach
                        </select>
                        @if ($deliveryChannel === 'direct')
                            <input type="tel" wire:model="customerPhone" class="f-input !py-2 !text-[12.5px]" placeholder="No. HP pelanggan" aria-label="Nomor HP pelanggan">
                            <textarea wire:model="deliveryAddress" rows="2" class="f-input !py-2 !text-[12.5px] col-span-2 resize-none" placeholder="Alamat pengantaran" aria-label="Alamat pengantaran"></textarea>
                        @elseif ($deliveryChannel !== '')
                            <input type="text" wire:model="deliveryReference" class="f-input !py-2 !text-[12.5px]" placeholder="No. pesanan aplikasi" aria-label="Nomor pesanan aplikasi">
                        @endif
                    </div>
                    <x-input-error :messages="array_merge($errors->get('delivery_channel'), $errors->get('delivery_address'), $errors->get('customer_phone'), $errors->get('delivery_reference'))" />
                @endif
                <div class="grid grid-cols-2 gap-2">
                    @if ($outlet->usesTables() && $serviceType === 'dine_in')
                        <button type="button" wire:click="openTablePicker('select')" @class(['f-input !py-2 !text-[12.5px] text-left truncate', 'a-border a-text font-semibold' => $selectedTable])>
                            <i class="fa-solid fa-chair mr-1"></i>{{ $selectedTable ? 'Meja '.$selectedTable->name : 'Pilih meja' }}
                        </button>
                    @endif
                    <div @class(['relative', 'col-span-2' => ! ($outlet->usesTables() && $serviceType === 'dine_in')]) x-data="{ open: false }" x-on:click.outside="open = false">
                        @if ($customer = $this->selectedCustomer)
                            <div class="f-input !py-2 !text-[12.5px] flex items-center gap-2 a-border">
                                <i class="fa-solid fa-user a-text"></i>
                                <span class="flex-1 truncate font-semibold text-pb-text">{{ $customer->name }}</span>
                                <button type="button" wire:click="clearCustomer" class="text-pb-muted" aria-label="Lepas pelanggan"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                        @else
                            <input type="text" wire:model.live.debounce.300ms="customerName" x-on:focus="open = true" x-on:input="open = true" class="f-input !py-2 !text-[12.5px] w-full"
                                   placeholder="Pelanggan{{ $this->canPayLater() && ! $outlet->usesTables() ? '' : ' (opsional)' }}" aria-label="Nama atau HP pelanggan" autocomplete="off">
                            @if (mb_strlen(trim($customerName)) >= 2)
                                <div x-show="open" x-cloak class="absolute z-30 left-0 right-0 top-full mt-1 rounded-xl border border-pb-border bg-pb-surface shadow-lg overflow-hidden">
                                    @foreach ($this->customerMatches as $match)
                                        <button type="button" wire:key="cm-{{ $match->id }}" wire:click="selectCustomer({{ $match->id }})" x-on:click="open = false" class="list-row w-full text-left !py-2 text-[12.5px]">
                                            <i class="fa-solid fa-user text-pb-muted"></i>
                                            <span class="flex-1 truncate text-pb-text">{{ $match->name }}</span>
                                            <span class="text-pb-muted">{{ $match->phone }}</span>
                                        </button>
                                    @endforeach
                                    <div class="px-3 py-2 border-t border-pb-border flex gap-2 items-center">
                                        <input type="tel" wire:model="newCustomerPhone" class="f-input !py-1.5 !text-[12px] flex-1" placeholder="No. HP (opsional)" aria-label="Nomor HP pelanggan baru">
                                        <button type="button" wire:click="createCustomer" class="btn-ghost text-[12px] font-semibold whitespace-nowrap"><i class="fa-solid fa-user-plus mr-1"></i>Simpan baru</button>
                                    </div>
                                    <x-input-error :messages="array_merge($errors->get('name'), $errors->get('phone'))" class="px-3 pb-2" />
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
                <x-input-error :messages="array_merge($errors->get('dining_table_id'), $errors->get('customer_name'), $errors->get('pay_later'))" />
                @endif
            </div>

            <div class="flex-1 overflow-y-auto px-5 py-3 flex flex-col divide-y divide-pb-border min-h-[120px]">
                @if ($activeOrder)
                    @foreach ($activeOrder->items->where('quantity', '>', 0) as $item)
                        <div class="py-2 flex gap-3 text-[12.5px]" wire:key="oi-{{ $item->id }}">
                            <div class="flex-1 min-w-0">
                                <div class="text-pb-text font-semibold">{{ $item->quantity }}× {{ $item->displayName() }}</div>
                                @if ($item->modifiers->isNotEmpty())
                                    <div class="text-[11.5px] text-pb-muted">+ {{ $item->modifiers->pluck('name')->join(', ') }}</div>
                                @endif
                            </div>
                            <div class="text-pb-sub shrink-0">{{ Money::format($item->line_total) }}</div>
                            @if ($this->canDiscount() && $item->quantity > 0)
                                <button type="button" x-data aria-label="Hapus item"
                                        x-on:click="const q = {{ $item->quantity }} > 1 ? parseInt(prompt('Hapus berapa porsi {{ addslashes($item->displayName()) }}? (1–{{ $item->quantity }})', '1')) : 1; if (! q) return; const r = prompt('Alasan menghapus item:'); if (r !== null) $wire.voidItem({{ $item->id }}, q, r)"
                                        class="text-pb-muted hover:text-[#f87171] shrink-0"><i class="fa-solid fa-trash-can text-[11px]"></i></button>
                            @endif
                        </div>
                    @endforeach
                    <div class="pt-2 pb-1 text-[11px] uppercase tracking-wider text-pb-muted">{{ $cart === [] ? 'Ketuk menu untuk menambah pesanan' : 'Tambahan' }}</div>
                @endif
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
                    @unless ($activeOrder)
                        <div class="py-8 text-center text-[12.5px] text-pb-muted">
                            <i class="fa-solid fa-hand-pointer text-xl mb-2 block"></i>Ketuk menu untuk menambahkan.
                        </div>
                    @endunless
                @endforelse
            </div>
            <x-input-error :messages="collect($errors->getMessages())->filter(fn ($v, $k) => str_starts_with($k, 'items'))->flatten()->all()" class="px-5" />

            <div class="px-5 py-4 border-t border-pb-border flex flex-col gap-2 text-[13px]">
                @if ($this->canDiscount() && ($cart !== [] || $activeOrder))
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

                @if ($this->canPayLater() || ($activeOrder && $cart !== []))
                    <button type="button" wire:click="saveOpenOrder" wire:loading.attr="disabled" wire:target="saveOpenOrder" class="btn btn-secondary w-full !py-2.5 mt-1" @disabled($cart === [])>
                        <i class="fa-solid fa-floppy-disk"></i>{{ $activeOrder ? 'Tambah ke Pesanan' : 'Simpan · Bayar Nanti' }}
                    </button>
                @endif
                <button type="button" wire:click="openPayment" class="btn btn-primary w-full !py-3" @disabled($cart === [] && ! $activeOrder)>
                    <i class="fa-solid fa-money-bill-wave"></i>Bayar {{ $cart !== [] || $activeOrder ? Money::format($totals->total) : '' }}
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

    {{-- ═════ Pemilih meja ═════ --}}
    <x-modal name="table-picker" maxWidth="2xl">
        <div class="px-6 py-5 border-b border-pb-border flex items-center justify-between">
            <div>
                <div class="font-display text-base font-bold text-pb-text">{{ $tablePickerMode === 'move' ? 'Pindah ke Meja' : 'Pilih Meja' }}</div>
                <div class="text-xs text-pb-muted mt-px">
                    {{ $outlet->tracksTableStatus() ? 'Merah = terisi. Ketuk meja dengan pesanan terbuka untuk membuka pesanannya.' : 'Status meja tidak dilacak di outlet ini.' }}
                </div>
            </div>
            <button type="button" x-on:click="$dispatch('close')" class="icon-btn !w-8 !h-8" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="px-6 py-5 flex flex-col gap-4 max-h-[65vh] overflow-y-auto">
            @forelse ($this->tables->groupBy(fn ($t) => $t->area ?: 'Tanpa area') as $area => $tables)
                <div wire:key="ta-{{ $area }}">
                    <div class="f-label">{{ $area }}</div>
                    <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                        @foreach ($tables as $table)
                            @php
                                $tableOrder = $table->openOrders->first();
                                $occupied = $outlet->tracksTableStatus() && $table->isOccupied();
                            @endphp
                            <div wire:key="tp-{{ $table->id }}" @class([
                                'rounded-xl border p-2.5 flex flex-col gap-1 text-center',
                                'border-[#f87171] bg-[rgba(248,113,113,.08)]' => $occupied,
                                'a-border a-bg-lt' => (string) $table->id === $tableId && ! $occupied,
                                'border-pb-border' => ! $occupied && (string) $table->id !== $tableId,
                            ])>
                                @if ($tableOrder && $tablePickerMode === 'select')
                                    <button type="button" wire:click="loadOrder({{ $tableOrder->id }})" class="flex flex-col items-center">
                                        <span class="font-display text-lg font-bold text-pb-text">{{ $table->name }}</span>
                                        <span class="text-[10.5px] trend-down font-semibold">{{ Money::format($tableOrder->total) }}</span>
                                    </button>
                                @else
                                    <button type="button" wire:click="selectTable({{ $table->id }})" class="flex flex-col items-center disabled:opacity-40"
                                            @disabled($tablePickerMode === 'move' && $tableOrder)>
                                        <span class="font-display text-lg font-bold text-pb-text">{{ $table->name }}</span>
                                        <span class="text-[10.5px] text-pb-muted">{{ $occupied ? 'Terisi' : ($table->capacity ? $table->capacity.' kursi' : 'Kosong') }}</span>
                                    </button>
                                @endif
                                @if ($occupied && ! $tableOrder)
                                    <button type="button" wire:click="clearTable({{ $table->id }})" class="text-[10.5px] link">Kosongkan</button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="text-[13px] text-pb-muted">
                    Belum ada meja terdaftar.
                    @can('tables.manage')<a href="{{ route('tables.index') }}" wire:navigate class="link">Tambahkan meja</a>.@endcan
                </p>
            @endforelse
            <x-input-error :messages="$errors->get('dining_table_id')" />
        </div>
    </x-modal>

    {{-- ═════ Gabung pesanan ═════ --}}
    @if ($activeOrder)
        <x-modal name="merge-orders" maxWidth="lg">
            <div class="px-6 py-5 border-b border-pb-border">
                <div class="font-display text-base font-bold text-pb-text">Gabungkan ke {{ $activeOrder->number }}</div>
                <div class="text-xs text-pb-muted mt-px">Item pesanan yang dipilih dipindah ke pesanan ini; pesanan asal ditutup.</div>
            </div>
            <div class="py-2 max-h-[60vh] overflow-y-auto">
                @foreach ($this->openOrders->reject(fn ($o) => $o->id === $activeOrder->id) as $other)
                    <button type="button" wire:key="mo-{{ $other->id }}" wire:click="mergeOrder({{ $other->id }})"
                            wire:confirm="Gabungkan {{ $other->number }} ke {{ $activeOrder->number }}?" class="list-row w-full text-left">
                        <div class="w-10 h-10 rounded-lg a-bg-lt a-text grid place-items-center font-display font-bold shrink-0">{{ $other->table_number ?: '—' }}</div>
                        <div class="flex-1 min-w-0">
                            <div class="text-[13px] font-semibold text-pb-text">{{ $other->number }}{{ $other->customer_name ? ' · '.$other->customer_name : '' }}</div>
                            <div class="text-[11.5px] text-pb-muted">{{ $other->items_count }} item</div>
                        </div>
                        <div class="text-[13px] font-bold text-pb-text">{{ Money::format($other->total) }}</div>
                    </button>
                @endforeach
            </div>
        </x-modal>
    @endif

    {{-- ═════ Pesanan terbuka (bayar nanti) ═════ --}}
    <x-modal name="open-orders" maxWidth="lg">
        <div class="px-6 py-5 border-b border-pb-border flex items-center justify-between">
            <div class="font-display text-base font-bold text-pb-text">Pesanan Terbuka</div>
            <button type="button" x-on:click="$dispatch('close')" class="icon-btn !w-8 !h-8" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="py-2 max-h-[60vh] overflow-y-auto">
            @forelse ($this->openOrders as $openOrder)
                <button type="button" wire:key="oo-{{ $openOrder->id }}" wire:click="loadOrder({{ $openOrder->id }})" class="list-row w-full text-left">
                    <div class="w-10 h-10 rounded-lg a-bg-lt a-text grid place-items-center font-display font-bold shrink-0">{{ $openOrder->table_number ?: '—' }}</div>
                    <div class="flex-1 min-w-0">
                        <div class="text-[13px] font-semibold text-pb-text">{{ $openOrder->number }}{{ $openOrder->customer_name ? ' · '.$openOrder->customer_name : '' }}</div>
                        <div class="text-[11.5px] text-pb-muted">{{ $openOrder->items_count }} item · {{ $openOrder->placed_at->timezone($outlet->business->timezone)->format('H:i') }} · {{ $openOrder->cashier?->name }}</div>
                    </div>
                    <div class="text-[13px] font-bold text-pb-text">{{ Money::format($openOrder->total) }}</div>
                </button>
            @empty
                <div class="px-6 py-8 text-center text-[13px] text-pb-muted">Tidak ada pesanan yang belum dibayar.</div>
            @endforelse
        </div>
    </x-modal>

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
                @if ($pendingTicket && $pendingTicket['order'] === $order->id)
                    <a href="{{ route('orders.kitchen-ticket', ['order' => $order->id, 'batch' => $pendingTicket['batch']]) }}" target="_blank" x-on:click="$wire.set('pendingTicket', null)" class="btn btn-secondary col-span-2"><i class="fa-solid fa-fire-burner"></i>Cetak Tiket Dapur</a>
                @endif
                <button type="button" x-on:click="$dispatch('close')" class="btn btn-primary col-span-2"><i class="fa-solid fa-plus"></i>Pesanan Baru</button>
            </div>
        @endif
    </x-modal>
    @endif {{-- shift --}}
@endif
</div>
