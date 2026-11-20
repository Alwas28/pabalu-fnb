<?php

namespace App\Support\Pos;

use App\Enums\CashMovementType;
use App\Enums\DeliveryChannel;
use App\Enums\DiscountType;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\ServiceType;
use App\Enums\ShiftStatus;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sinkronisasi antrean transaksi dari aplikasi kasir Android yang sempat offline.
 *
 * Operasi diproses berurutan, masing-masing dalam transaksi sendiri, dan idempoten lewat client_reference:
 * - shift_open, cash_movement, order, shift_close.
 *
 * Prinsip: transaksi offline sudah terjadi (uang diterima, struk tercetak), jadi data yang wajar
 * selalu DITERIMA apa adanya — harga & total yang tercetak di struk dipakai. Perbedaan dengan data
 * server (harga berubah, menu dihapus, diskon tanpa izin, terlalu lama offline, …) dicatat di
 * orders.sync_issues untuk ditinjau owner. Operasi hanya DITOLAK bila datanya rusak/tidak konsisten
 * (mis. total tidak sama dengan jumlah item, pembayaran kurang) — perangkat menyimpannya untuk diperiksa.
 */
class OfflineSync
{
    public const MAX_OPERATIONS = 100;

    /** Toleransi jam perangkat yang lebih cepat dari server. */
    private const CLOCK_SKEW_MINUTES = 10;

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return list<array{index: int, type: ?string, client_reference: ?string, status: string, id?: int, number?: string, issues?: list<string>, errors?: array<string, list<string>>}>
     */
    public function handle(User $user, PosDevice $device, array $operations): array
    {
        $outlet = $device->outlet;
        $results = [];

        foreach (array_values($operations) as $index => $operation) {
            $type = is_array($operation) ? ($operation['type'] ?? null) : null;
            $base = ['index' => $index, 'type' => is_string($type) ? $type : null, 'client_reference' => is_array($operation) ? ($operation['client_reference'] ?? null) : null];

            try {
                $results[] = $base + DB::transaction(fn () => match ($type) {
                    'shift_open' => $this->openShift($user, $device, $operation),
                    'cash_movement' => $this->recordMovement($user, $device, $operation),
                    'order' => $this->placeOrder($user, $device, $operation),
                    'shift_close' => $this->closeShift($user, $device, $operation),
                    default => throw ValidationException::withMessages(['type' => 'Jenis operasi tidak dikenal.']),
                });
            } catch (ValidationException $e) {
                $results[] = $base + ['status' => 'rejected', 'errors' => $e->errors()];
            }
        }

        $device->forceFill(['last_synced_at' => now()])->save();

        return $results;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: string, id: int}
     */
    private function openShift(User $user, PosDevice $device, array $input): array
    {
        $data = Validator::make($input, [
            'client_reference' => ['required', 'string', 'max:64'],
            'opening_cash' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'opening_note' => ['nullable', 'string', 'max:255'],
            'opened_at' => ['required', 'date'],
        ])->validate();

        $existing = CashShift::where('business_id', $device->business_id)->where('client_reference', $data['client_reference'])->first();

        if ($existing) {
            return ['status' => 'duplicate', 'id' => $existing->id];
        }

        $shift = CashShift::create([
            'business_id' => $device->business_id,
            'outlet_id' => $device->outlet_id,
            'user_id' => $user->id,
            'client_reference' => $data['client_reference'],
            'pos_device_id' => $device->id,
            'status' => ShiftStatus::Open,
            'opening_cash' => (int) $data['opening_cash'],
            'opening_note' => $data['opening_note'] ?? null,
            'opened_at' => $this->deviceTime($data['opened_at']),
        ]);

        return ['status' => 'created', 'id' => $shift->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: string, id: int}
     */
    private function recordMovement(User $user, PosDevice $device, array $input): array
    {
        $data = Validator::make($input, [
            'client_reference' => ['required', 'string', 'max:64'],
            'movement_type' => ['required', Rule::enum(CashMovementType::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'reason' => ['required', 'string', 'max:255'],
            'created_at' => ['required', 'date'],
            ...$this->shiftRules(),
        ])->validate();

        $shift = $this->shift($user, $device, $data);
        $existing = $shift->movements()->where('client_reference', $data['client_reference'])->first();

        if ($existing) {
            return ['status' => 'duplicate', 'id' => $existing->id];
        }

        $movement = new CashMovement([
            'type' => $data['movement_type'],
            'amount' => (int) $data['amount'],
            'reason' => $data['reason'],
            'user_id' => $user->id,
            'client_reference' => $data['client_reference'],
        ]);
        $movement->created_at = $this->deviceTime($data['created_at']);
        $shift->movements()->save($movement);

        return ['status' => 'created', 'id' => $movement->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: string, id: int}
     */
    private function closeShift(User $user, PosDevice $device, array $input): array
    {
        $data = Validator::make($input, [
            'actual_cash' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'closing_note' => ['nullable', 'string', 'max:500'],
            'closed_at' => ['required', 'date'],
            ...$this->shiftRules(),
        ])->validate();

        $shift = CashShift::whereKey($this->shift($user, $device, $data)->id)->lockForUpdate()->firstOrFail();

        if (! $shift->isOpen()) {
            return ['status' => 'duplicate', 'id' => $shift->id];
        }

        $summary = app(ShiftManager::class)->summary($shift);
        $actual = (int) $data['actual_cash'];

        $shift->update([
            'status' => ShiftStatus::Closed,
            'closed_at' => $this->deviceTime($data['closed_at']),
            'closed_by' => $user->id,
            'expected_cash' => $summary['expected_cash'],
            'actual_cash' => $actual,
            'cash_difference' => $actual - $summary['expected_cash'],
            'closing_note' => $data['closing_note'] ?? null,
            'summary' => $summary,
        ]);

        return ['status' => 'created', 'id' => $shift->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: string, id: int, number: string, issues: list<string>}
     */
    private function placeOrder(User $user, PosDevice $device, array $input): array
    {
        $outlet = $device->outlet;

        if (! empty($input['client_reference']) && is_string($input['client_reference'])) {
            $existing = Order::where('business_id', $outlet->business_id)->where('client_reference', $input['client_reference'])->first();

            if ($existing) {
                return ['status' => 'duplicate', 'id' => $existing->id, 'number' => $existing->number, 'issues' => $existing->sync_issues ?? []];
            }
        }

        $data = Validator::make($input, $this->orderRules($outlet, $device, $input), [
            'number.regex' => 'Format nomor harus '.$outlet->code.'-YYMMDD-'.$device->code.'-0001.',
        ])->validate();

        $shift = $this->shift($user, $device, $data);
        $placedAt = $this->deviceTime($data['placed_at']);
        $issues = [];

        if ($placedAt->lt(now()->subHours($outlet->offline_max_hours))) {
            $issues[] = "Disinkron lebih dari {$outlet->offline_max_hours} jam setelah transaksi.";
        }

        if ($placedAt->gt(now()->addMinutes(self::CLOCK_SKEW_MINUTES))) {
            $issues[] = 'Jam perangkat lebih cepat dari server; waktu transaksi disesuaikan ke waktu sinkron.';
            $placedAt = CarbonImmutable::now();
        }

        $lines = $this->resolveLines($outlet, $data['items'], $issues);
        $subtotal = array_sum(array_column($lines, 'line_total'));
        $totals = $data['totals'];

        $this->assertConsistentTotals($subtotal, $totals);

        $discountType = isset($data['discount_type']) ? DiscountType::from($data['discount_type']) : null;

        if ($totals['discount_amount'] > 0 && ! $user->hasPermission(Permission::ApproveTransactions)) {
            $issues[] = 'Diskon '.Money::format($totals['discount_amount']).' diberikan oleh akun tanpa izin diskon.';
        }

        $server = OrderTotals::calculate($outlet, $subtotal, $discountType, (float) ($data['discount_value'] ?? 0));

        if ($server->total !== (int) $totals['total']) {
            $issues[] = 'Total di perangkat '.Money::format($totals['total']).', menurut pengaturan pajak/service saat ini '.Money::format($server->total).'.';
        }

        $serviceType = ServiceType::from($data['service_type']);

        if (! $outlet->service_types->contains($serviceType)) {
            $issues[] = "Layanan {$serviceType->label()} tidak aktif di outlet.";
        }

        foreach ($data['payments'] as $payment) {
            if (! $outlet->payment_methods->contains(PaymentMethod::from($payment['method']))) {
                $issues[] = 'Metode bayar '.PaymentMethod::from($payment['method'])->label().' tidak aktif di outlet.';
            }
        }

        $payments = PaymentAllocator::allocate($data['payments'], (int) $totals['total']);

        $table = ! empty($data['dining_table_id']) ? $outlet->diningTables()->find($data['dining_table_id']) : null;
        $customer = ! empty($data['customer_id']) ? Customer::where('business_id', $outlet->business_id)->find($data['customer_id']) : null;

        if (! empty($data['customer_id']) && ! $customer) {
            $issues[] = 'Pelanggan di perangkat tidak ditemukan di server.';
        }

        $number = $this->uniqueNumber($outlet, $data['number'], $issues);
        $issues = array_values(array_unique($issues));

        $order = Order::create([
            'business_id' => $outlet->business_id,
            'outlet_id' => $outlet->id,
            'cashier_id' => $user->id,
            'cash_shift_id' => $shift->id,
            'number' => $number,
            'receipt_token' => Str::random(32),
            'client_reference' => $data['client_reference'],
            'pos_device_id' => $device->id,
            'placed_offline' => true,
            'synced_at' => now(),
            'sync_issues' => $issues ?: null,
            'service_type' => $serviceType,
            'table_number' => $table?->name ?? ($data['table_name'] ?? null),
            'dining_table_id' => $table?->id,
            'customer_id' => $customer?->id,
            'customer_name' => ($data['customer_name'] ?? null) ?: $customer?->name,
            'customer_phone' => ($data['customer_phone'] ?? null) ?: $customer?->phone,
            'delivery_address' => $data['delivery_address'] ?? null,
            'delivery_channel' => $serviceType === ServiceType::Delivery ? ($data['delivery_channel'] ?? DeliveryChannel::Other->value) : null,
            'delivery_reference' => $data['delivery_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => OrderStatus::Confirmed,
            'payment_status' => PaymentStatus::Pending,
            'placed_at' => $placedAt,
            'completed_at' => $placedAt,
            'subtotal' => $subtotal,
            'discount_type' => $discountType,
            'discount_value' => $discountType ? (float) $data['discount_value'] : 0,
            'discount_amount' => (int) $totals['discount_amount'],
            'discount_reason' => $discountType ? ($data['discount_reason'] ?? null) : null,
            'service_charge_rate' => (float) $totals['service_charge_rate'],
            'service_charge_amount' => (int) $totals['service_charge_amount'],
            'tax_rate' => (float) $totals['tax_rate'],
            'prices_include_tax' => (bool) $totals['prices_include_tax'],
            'tax_amount' => (int) $totals['tax_amount'],
            'total' => (int) $totals['total'],
        ]);

        $created = [];
        $needsKitchen = MenuItem::whereIn('id', collect($lines)->pluck('menu_item_id')->filter())->pluck('needs_kitchen', 'id');

        foreach ($lines as $line) {
            $modifiers = $line['modifiers'];
            unset($line['modifiers']);

            // Pesanan offline sudah dibuat & disajikan (tiket dicetak di outlet), tidak masuk antrean dapur.
            $item = $created[] = $order->items()->create($line + [
                'kitchen_status' => KitchenItemStatus::Served,
                'kitchen_batch' => 1,
                'kitchen_updated_at' => $placedAt,
                'sent_to_kitchen' => (bool) ($needsKitchen[$line['menu_item_id'] ?? 0] ?? true),
            ]);

            foreach ($modifiers as $modifier) {
                $item->modifiers()->create($modifier);
            }
        }

        OrderEvents::itemsAdded($order, $created);
        OrderWriter::writePayments($order, $payments, $user, $shift, $placedAt);
        OrderWriter::history($order, null, OrderStatus::Confirmed, $user, "Dibuat offline di perangkat {$device->code} ({$device->name})");
        KitchenFlow::sync($order, $user, 'Disinkron dari perangkat offline');

        return ['status' => 'created', 'id' => $order->id, 'number' => $order->number, 'issues' => $issues];
    }

    /**
     * Baris item sesuai yang tercetak di perangkat. Menu yang sudah dihapus tetap dicatat dengan namanya;
     * perbedaan harga dengan server dicatat sebagai catatan sinkron.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $issues
     * @return list<array<string, mixed>>
     */
    private function resolveLines(Outlet $outlet, array $items, array &$issues): array
    {
        $menuItems = MenuItem::where('business_id', $outlet->business_id)
            ->whereIn('id', array_filter(array_column($items, 'menu_item_id')))
            ->with(['variants', 'modifierGroups.modifiers', 'outlets' => fn ($q) => $q->whereKey($outlet->id)])
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($items as $item) {
            $menu = isset($item['menu_item_id']) ? $menuItems->get((int) $item['menu_item_id']) : null;
            $variant = $menu && ! empty($item['variant_id']) ? $menu->variants->firstWhere('id', (int) $item['variant_id']) : null;
            $knownModifiers = $menu ? $menu->modifierGroups->flatMap->modifiers->keyBy('id') : collect();
            $unitPrice = (int) $item['unit_price'];
            $quantity = (int) $item['quantity'];

            $modifiers = array_map(fn (array $m) => [
                'modifier_id' => isset($m['modifier_id']) && $knownModifiers->has((int) $m['modifier_id']) ? (int) $m['modifier_id'] : null,
                'group_name' => $m['group_name'] ?? null,
                'name' => $m['name'],
                'price' => (int) $m['price'],
            ], $item['modifiers'] ?? []);

            if (! $menu) {
                $issues[] = "Menu \"{$item['name']}\" tidak ada lagi di server.";
            } else {
                $serverPrice = max(0, $menu->priceAt($outlet) + ($variant?->price_adjustment ?? 0))
                    + $knownModifiers->only(array_filter(array_column($modifiers, 'modifier_id')))->sum('price');

                if ($serverPrice !== $unitPrice) {
                    $issues[] = "Harga {$item['name']} di perangkat ".Money::format($unitPrice).', di server '.Money::format($serverPrice).'.';
                }
            }

            $lines[] = [
                'menu_item_id' => $menu?->id,
                'menu_variant_id' => $variant?->id,
                'name' => $item['name'],
                'variant_name' => $item['variant_name'] ?? $variant?->name,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => $unitPrice * $quantity,
                'notes' => $item['notes'] ?? null,
                'modifiers' => $modifiers,
            ];
        }

        return $lines;
    }

    /**
     * Total di perangkat harus konsisten dengan item & komponennya sendiri.
     *
     * @param  array<string, mixed>  $totals
     *
     * @throws ValidationException
     */
    private function assertConsistentTotals(int $subtotal, array $totals): void
    {
        if ((int) $totals['subtotal'] !== $subtotal) {
            throw ValidationException::withMessages(['totals.subtotal' => 'Subtotal tidak sama dengan jumlah harga item.']);
        }

        if ((int) $totals['discount_amount'] > $subtotal) {
            throw ValidationException::withMessages(['totals.discount_amount' => 'Diskon melebihi subtotal.']);
        }

        $expected = $subtotal - (int) $totals['discount_amount'] + (int) $totals['service_charge_amount']
            + ($totals['prices_include_tax'] ? 0 : (int) $totals['tax_amount']);

        if ($expected !== (int) $totals['total']) {
            throw ValidationException::withMessages(['totals.total' => 'Total tidak sama dengan subtotal − diskon + service charge + pajak.']);
        }
    }

    /**
     * Nomor dari perangkat dipakai apa adanya. Bila sudah terpakai (mis. aplikasi dipasang ulang dan
     * urutannya mulai dari awal), diberi akhiran huruf agar tetap unik.
     *
     * @param  list<string>  $issues
     */
    private function uniqueNumber(Outlet $outlet, string $number, array &$issues): string
    {
        $candidate = $number;
        $suffix = 'A';

        while (Order::where('outlet_id', $outlet->id)->where('number', $candidate)->exists()) {
            $candidate = $number.$suffix++;
        }

        if ($candidate !== $number) {
            $issues[] = "Nomor {$number} sudah dipakai; disimpan sebagai {$candidate}.";
        }

        return $candidate;
    }

    /**
     * Shift tujuan: shift server (shift_id) atau shift yang dibuka offline (shift_reference).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function shift(User $user, PosDevice $device, array $data): CashShift
    {
        $shift = CashShift::where('outlet_id', $device->outlet_id)
            ->where('user_id', $user->id)
            ->when(! empty($data['shift_id']),
                fn ($q) => $q->whereKey($data['shift_id']),
                fn ($q) => $q->where('client_reference', $data['shift_reference'] ?? ''))
            ->first();

        return $shift ?? throw ValidationException::withMessages(['shift' => 'Shift tidak ditemukan. Kirim operasi shift_open lebih dulu.']);
    }

    /** @return array<string, mixed> */
    private function shiftRules(): array
    {
        return [
            'shift_id' => ['required_without:shift_reference', 'nullable', 'integer'],
            'shift_reference' => ['required_without:shift_id', 'nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function orderRules(Outlet $outlet, PosDevice $device, array $input): array
    {
        $numberPattern = '/^'.preg_quote($outlet->code, '/').'-\d{6}-'.preg_quote($device->code, '/').'-\d{4,}$/';

        return [
            'client_reference' => ['required', 'string', 'max:64'],
            'number' => ['required', 'string', 'max:36', 'regex:'.$numberPattern],
            'placed_at' => ['required', 'date'],
            ...$this->shiftRules(),
            'service_type' => ['required', Rule::enum(ServiceType::class)],
            'dining_table_id' => ['nullable', 'integer'],
            'table_name' => ['nullable', 'string', 'max:20'],
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'delivery_channel' => ['nullable', Rule::enum(DeliveryChannel::class)],
            'delivery_reference' => ['nullable', 'string', 'max:64'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.menu_item_id' => ['nullable', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string', 'max:150'],
            'items.*.variant_name' => ['nullable', 'string', 'max:100'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.modifiers' => ['nullable', 'array', 'max:30'],
            'items.*.modifiers.*.modifier_id' => ['nullable', 'integer'],
            'items.*.modifiers.*.group_name' => ['nullable', 'string', 'max:100'],
            'items.*.modifiers.*.name' => ['required', 'string', 'max:100'],
            'items.*.modifiers.*.price' => ['required', 'integer', 'min:0'],
            ...OrderWriter::discountRules($input),
            'totals' => ['required', 'array'],
            'totals.subtotal' => ['required', 'integer', 'min:0'],
            'totals.discount_amount' => ['required', 'integer', 'min:0'],
            'totals.service_charge_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'totals.service_charge_amount' => ['required', 'integer', 'min:0'],
            'totals.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'totals.prices_include_tax' => ['required', 'boolean'],
            'totals.tax_amount' => ['required', 'integer', 'min:0'],
            'totals.total' => ['required', 'integer', 'min:0'],
            ...PaymentAllocator::rules(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::cases())),
        ];
    }

    /** Waktu dari perangkat (ISO 8601 dengan zona waktu) → UTC aplikasi. */
    private function deviceTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
    }
}
