<?php

namespace App\Support\Purchasing;

use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Siklus pembelian: buat / ubah PO (draft) → pesan → terima barang (bertahap; stok & HPP outlet
 * diperbarui) → bayar tagihan → retur barang. PO dibatalkan hanya bila belum ada barang diterima.
 */
class PurchaseService
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * Simpan PO draft (baru atau ubah).
     *
     * @param  array<string, mixed>  $input  supplier_id, order_date, expected_date, due_date, notes,
     *                                       items[ingredient_id, quantity, unit (base|purchase), unit_price]
     */
    public function saveDraft(User $user, Outlet $outlet, array $input, ?PurchaseOrder $order = null): PurchaseOrder
    {
        if ($order && ! $order->isEditable()) {
            throw ValidationException::withMessages(['order' => 'PO yang sudah dipesan tidak bisa diubah.']);
        }

        $data = Validator::make($input, [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $outlet->business_id)],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.ingredient_id' => ['required', 'integer', Rule::exists('ingredients', 'id')->where('business_id', $outlet->business_id)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.unit' => ['required', Rule::in(['base', 'purchase'])],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ], ['items.required' => 'Tambahkan minimal satu bahan.'],
            ['supplier_id' => 'supplier', 'items.*.quantity' => 'jumlah', 'items.*.unit_price' => 'harga', 'items.*.ingredient_id' => 'bahan'])->validate();

        return DB::transaction(function () use ($user, $outlet, $data, $order) {
            $attributes = [
                'supplier_id' => $data['supplier_id'],
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($order) {
                $order->update($attributes);
                $order->items()->delete();
            } else {
                Outlet::whereKey($outlet->id)->lockForUpdate()->first();
                $order = PurchaseOrder::create($attributes + [
                    'business_id' => $outlet->business_id,
                    'outlet_id' => $outlet->id,
                    'number' => $this->nextNumber($outlet),
                    'status' => PurchaseStatus::Draft,
                    'created_by' => $user->id,
                ]);
            }

            $total = 0;
            foreach ($data['items'] as $line) {
                $ingredient = Ingredient::findOrFail($line['ingredient_id']);
                $usePurchaseUnit = $line['unit'] === 'purchase' && $ingredient->purchase_unit && (float) $ingredient->purchase_unit_size > 0;
                $lineTotal = (int) round((float) $line['quantity'] * (int) $line['unit_price']);
                $total += $lineTotal;

                $order->items()->create([
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $line['quantity'],
                    'unit_label' => $usePurchaseUnit ? $ingredient->purchase_unit : $ingredient->base_unit->value,
                    'unit_size' => $usePurchaseUnit ? $ingredient->purchase_unit_size : 1,
                    'unit_price' => (int) $line['unit_price'],
                    'line_total' => $lineTotal,
                ]);
            }

            $order->update(['total' => $total]);

            return $order;
        });
    }

    public function markOrdered(PurchaseOrder $order): PurchaseOrder
    {
        if (! $order->isEditable()) {
            throw ValidationException::withMessages(['order' => 'PO ini sudah dipesan.']);
        }

        $order->update(['status' => PurchaseStatus::Ordered]);

        return $order;
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->received_total > 0 || ! in_array($order->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered], true)) {
            throw ValidationException::withMessages(['order' => 'PO yang sudah menerima barang tidak bisa dibatalkan. Gunakan retur.']);
        }

        $order->update(['status' => PurchaseStatus::Cancelled]);

        return $order;
    }

    /**
     * Terima barang. Jumlah dalam satuan PO (satuan beli). Stok outlet bertambah dengan HPP = harga PO.
     *
     * @param  array<int|string, string|float|null>  $quantities  purchase_order_item_id → jumlah diterima
     */
    public function receive(User $user, PurchaseOrder $order, array $quantities, ?string $notes = null): PurchaseOrder
    {
        if (! $order->canReceive()) {
            throw ValidationException::withMessages(['receive' => 'PO harus berstatus dipesan untuk menerima barang.']);
        }

        return DB::transaction(function () use ($user, $order, $quantities, $notes) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $items = $order->items()->with('ingredient')->get()->keyBy('id');
            $lines = [];

            foreach ($quantities as $itemId => $value) {
                if ($value === null || $value === '' || (float) $value == 0.0) {
                    continue;
                }

                $item = $items->get((int) $itemId);
                if (! $item || ! is_numeric($value) || (float) $value < 0) {
                    throw ValidationException::withMessages(["receive.$itemId" => 'Jumlah tidak valid.']);
                }

                $base = round((float) $value * (float) $item->unit_size, 3);
                if ($base > $item->remainingBase() + 0.0005) {
                    throw ValidationException::withMessages(["receive.$itemId" => 'Melebihi sisa pesanan ('.Ingredient::qty($item->remainingBase() / (float) $item->unit_size).' '.$item->unit_label.').']);
                }

                $lines[] = [$item, $base];
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['receive' => 'Isi jumlah barang yang diterima.']);
            }

            $receipt = $order->receipts()->create(['received_at' => now(), 'notes' => $notes, 'user_id' => $user->id]);
            $value = 0;

            foreach ($lines as [$item, $base]) {
                $cost = $item->unitCost();
                $receipt->items()->create(['purchase_order_item_id' => $item->id, 'base_quantity' => $base, 'unit_cost' => $cost]);
                $item->update(['received_base_quantity' => (float) $item->received_base_quantity + $base]);
                $value += (int) round($base * $cost);

                $this->ledger->move($order->outlet, $item->ingredient, StockMovementType::PurchaseReceipt, $base, $cost, $order,
                    $order->number.' · '.$order->supplier->name, $user);
            }

            $complete = $order->items()->get()->every(fn (PurchaseOrderItem $i) => $i->remainingBase() <= 0.0005);

            $order->update([
                'received_total' => min($order->total, $order->received_total + $value),
                'status' => $complete ? PurchaseStatus::Received : PurchaseStatus::Partial,
            ]);

            return $order;
        });
    }

    /**
     * Bayar tagihan supplier (boleh dicicil, tidak boleh melebihi sisa tagihan).
     *
     * @param  array<string, mixed>  $input  amount, method, paid_at, reference
     */
    public function pay(User $user, PurchaseOrder $order, array $input): PurchaseOrder
    {
        $data = Validator::make($input, [
            'amount' => ['required', 'integer', 'min:1', 'max:'.max(1, $order->outstanding())],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], ['amount.max' => 'Melebihi sisa tagihan ('.Money::format($order->outstanding()).').'],
            ['amount' => 'nominal', 'method' => 'metode', 'paid_at' => 'tanggal bayar'])->validate();

        if ($order->outstanding() === 0) {
            throw ValidationException::withMessages(['amount' => 'Tidak ada tagihan yang perlu dibayar.']);
        }

        return DB::transaction(function () use ($user, $order, $data) {
            $order->payments()->create($data + ['user_id' => $user->id]);
            $order->update(['paid_amount' => $order->paid_amount + (int) $data['amount']]);

            return $order;
        });
    }

    /**
     * Retur barang yang sudah diterima: stok berkurang, tagihan berkurang.
     *
     * @param  array<int|string, string|float|null>  $quantities  purchase_order_item_id → jumlah (satuan PO)
     */
    public function returnGoods(User $user, PurchaseOrder $order, array $quantities, string $reason): PurchaseOrder
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan retur'])->validate();

        return DB::transaction(function () use ($user, $order, $quantities, $reason) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $items = $order->items()->with('ingredient')->get()->keyBy('id');
            $lines = [];

            foreach ($quantities as $itemId => $value) {
                if ($value === null || $value === '' || (float) $value == 0.0) {
                    continue;
                }

                $item = $items->get((int) $itemId);
                $base = $item ? round((float) $value * (float) $item->unit_size, 3) : 0;

                if (! $item || $base < 0 || $base > $item->returnableBase() + 0.0005) {
                    throw ValidationException::withMessages(["return.$itemId" => 'Jumlah retur melebihi barang yang diterima.']);
                }

                $lines[] = [$item, $base];
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['return' => 'Isi jumlah barang yang diretur.']);
            }

            $return = $order->returns()->create(['reason' => $reason, 'total' => 0, 'user_id' => $user->id]);
            $total = 0;

            foreach ($lines as [$item, $base]) {
                $cost = $item->unitCost();
                $amount = (int) round($base * $cost);
                $total += $amount;

                $return->items()->create(['purchase_order_item_id' => $item->id, 'base_quantity' => $base, 'unit_cost' => $cost, 'amount' => $amount]);
                $item->update(['returned_base_quantity' => (float) $item->returned_base_quantity + $base]);
                $this->ledger->move($order->outlet, $item->ingredient, StockMovementType::PurchaseReturn, -$base, reference: $order,
                    note: 'Retur '.$order->number.': '.$reason, user: $user);
            }

            $return->update(['total' => $total]);
            $order->update(['returned_total' => $order->returned_total + $total]);

            return $order;
        });
    }

    /** PO-KDI-2611-0001 (per outlet per bulan). */
    private function nextNumber(Outlet $outlet): string
    {
        $prefix = 'PO-'.$outlet->code.'-'.now($outlet->business->timezone ?: config('app.timezone'))->format('ym').'-';
        $last = PurchaseOrder::where('outlet_id', $outlet->id)->where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');

        return $prefix.str_pad((string) ($last ? (int) Str::afterLast($last, '-') + 1 : 1), 4, '0', STR_PAD_LEFT);
    }
}
