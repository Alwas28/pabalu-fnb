<?php

namespace App\Support\Pos;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\CashShift;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Operasi tulis bersama untuk order: item, total, pembayaran, riwayat status & status meja.
 * Dipanggil dari dalam transaksi DB oleh kelas-kelas aksi di App\Support\Pos.
 */
class OrderWriter
{
    /**
     * @param  list<CartLine>  $lines
     */
    public static function writeLines(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            $item = $order->items()->create([
                'menu_item_id' => $line->menuItem->id,
                'menu_variant_id' => $line->variant?->id,
                'name' => $line->menuItem->name,
                'variant_name' => $line->variant?->name,
                'unit_price' => $line->unitPrice,
                'quantity' => $line->quantity,
                'line_total' => $line->lineTotal(),
                'notes' => $line->notes,
            ]);

            foreach ($line->modifiers as $modifier) {
                $item->modifiers()->create([
                    'modifier_id' => $modifier->id,
                    'group_name' => $modifier->group->name,
                    'name' => $modifier->name,
                    'price' => $modifier->price,
                ]);
            }
        }
    }

    /**
     * Diskon dari input; hanya Supervisor ke atas (izin menyetujui transaksi) yang boleh memberi diskon.
     *
     * @param  array<string, mixed>  $data  discount_type, discount_value
     * @return array{0: ?DiscountType, 1: float}
     *
     * @throws ValidationException
     */
    public static function discount(User $cashier, array $data): array
    {
        $type = isset($data['discount_type']) ? DiscountType::tryFrom((string) $data['discount_type']) : null;
        $value = (float) ($data['discount_value'] ?? 0);

        if (! $type || $value <= 0) {
            return [null, 0.0];
        }

        if (! $cashier->hasPermission(Permission::ApproveTransactions)) {
            throw ValidationException::withMessages(['discount_value' => 'Kamu tidak berhak memberikan diskon. Minta bantuan supervisor.']);
        }

        return [$type, $value];
    }

    /**
     * Aturan validasi diskon.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function discountRules(array $input): array
    {
        return [
            'discount_type' => ['nullable', Rule::enum(DiscountType::class)],
            'discount_value' => ['nullable', 'numeric', 'min:0', Rule::when(($input['discount_type'] ?? null) === DiscountType::Percent->value, ['max:100'])],
            'discount_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Atribut total order dari hasil perhitungan. */
    public static function totalsAttributes(OrderTotals $totals, ?DiscountType $discountType, float $discountValue, ?string $discountReason): array
    {
        return [
            'subtotal' => $totals->subtotal,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => $totals->discount,
            'discount_reason' => $discountType ? $discountReason : null,
            'service_charge_rate' => $totals->serviceChargeRate,
            'service_charge_amount' => $totals->serviceCharge,
            'tax_rate' => $totals->taxRate,
            'prices_include_tax' => $totals->pricesIncludeTax,
            'tax_amount' => $totals->tax,
            'total' => $totals->total,
        ];
    }

    /**
     * @param  list<array{method: PaymentMethod, amount: int, tendered_amount: int, change_amount: int, reference: ?string}>  $payments
     */
    public static function writePayments(Order $order, array $payments, User $cashier, CashShift $shift): void
    {
        $now = now();

        foreach ($payments as $payment) {
            $order->payments()->create($payment + [
                'cash_shift_id' => $shift->id,
                'status' => PaymentStatus::Paid,
                'received_by' => $cashier->id,
                'paid_at' => $now,
            ]);
        }

        $order->forceFill([
            'paid_amount' => array_sum(array_column($payments, 'amount')),
            'change_amount' => array_sum(array_column($payments, 'change_amount')),
            'payment_status' => PaymentStatus::Paid,
        ])->save();
    }

    public static function history(Order $order, ?OrderStatus $from, OrderStatus $to, ?User $user, ?string $note = null): void
    {
        $order->statusHistories()->create([
            'user_id' => $user?->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /** Tandai meja terisi (bila outlet melacak status meja). */
    public static function occupy(?DiningTable $table): void
    {
        if ($table && $table->outlet->tracksTableStatus() && ! $table->isOccupied()) {
            $table->forceFill(['occupied_at' => now()])->save();
        }
    }

    /** Kosongkan meja bila tidak ada open bill lain di meja itu. */
    public static function releaseIfFree(?DiningTable $table): void
    {
        if ($table && $table->isOccupied() && ! $table->openOrders()->exists()) {
            $table->forceFill(['occupied_at' => null])->save();
        }
    }

    /**
     * Pastikan order masih open bill (bisa ditambah / dibayar / dibatalkan).
     *
     * @throws ValidationException
     */
    public static function ensureOpen(Order $order): void
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages([
                'order' => 'Pesanan ini sudah '.($order->status === OrderStatus::Cancelled ? 'dibatalkan' : 'dibayar').'.',
            ]);
        }
    }
}
