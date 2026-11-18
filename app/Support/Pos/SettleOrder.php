<?php

namespace App\Support\Pos;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Pelunasan open bill: diskon (opsional, Supervisor ke atas), split payment, lalu order selesai.
 * Pembayaran dicatat ke shift kasir yang menerima, bukan shift saat pesanan dibuka.
 */
class SettleOrder
{
    /**
     * @param  array<string, mixed>  $input  payments, discount_type, discount_value, discount_reason
     *
     * @throws ValidationException
     */
    public function handle(User $cashier, Order $order, array $input): Order
    {
        $outlet = $order->outlet;
        $shift = ShiftManager::require($cashier, $outlet);
        OrderWriter::ensureOpen($order);

        $data = Validator::make(
            $input,
            [...OrderWriter::discountRules($input), ...PaymentAllocator::rules($outlet->payment_methods->map->value->all())],
            PaymentAllocator::messages(),
        )->validate();

        [$discountType, $discountValue] = OrderWriter::discount($cashier, $data);

        return DB::transaction(function () use ($cashier, $order, $outlet, $shift, $data, $discountType, $discountValue) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            OrderWriter::ensureOpen($order);

            $totals = OrderTotals::calculate($outlet, (int) $order->items()->sum('line_total'), $discountType, $discountValue);
            $payments = PaymentAllocator::allocate($data['payments'], $totals->total);
            $from = $order->status;

            $order->update(OrderWriter::totalsAttributes($totals, $discountType, $discountValue, $data['discount_reason'] ?? null) + [
                'status' => OrderStatus::Completed,
                'completed_at' => now(),
            ]);

            OrderWriter::writePayments($order, $payments, $cashier, $shift);
            OrderWriter::history($order, $from, OrderStatus::Completed, $cashier, 'Pesanan dilunasi');
            OrderWriter::releaseIfFree($order->diningTable);

            return $order;
        });
    }
}
