<?php

namespace App\Support\Pos;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gabungkan open bill lain (sumber) ke open bill ini (tujuan) — mis. dua meja digabung.
 *
 * Item sumber pindah ke tujuan sebagai batch dapur berikutnya (status dapurnya tetap),
 * jejak pemakaian bahan ikut dipindah, total dihitung ulang. Order sumber ditutup dengan
 * status "Dibatalkan" & alasan "Digabung ke …" (bukan void: tidak ada uang yang dikembalikan).
 */
class MergeOrders
{
    /**
     * @throws ValidationException
     */
    public function handle(User $user, Order $target, Order $source): Order
    {
        ShiftManager::require($user, $target->outlet);

        if ($target->is($source) || $target->outlet_id !== $source->outlet_id) {
            throw ValidationException::withMessages(['source_order_id' => 'Pilih pesanan lain di outlet yang sama.']);
        }

        OrderWriter::ensureOpen($target);
        OrderWriter::ensureOpen($source);

        return DB::transaction(function () use ($user, $target, $source) {
            [$target, $source] = [
                Order::whereKey($target->id)->lockForUpdate()->firstOrFail(),
                Order::whereKey($source->id)->lockForUpdate()->firstOrFail(),
            ];

            $offset = (int) $target->items()->max('kitchen_batch');
            foreach ($source->items()->get() as $item) {
                $item->update(['order_id' => $target->id, 'kitchen_batch' => $offset + $item->kitchen_batch]);
            }

            StockMovement::where('reference_type', $source->getMorphClass())->where('reference_id', $source->id)
                ->update(['reference_id' => $target->id]);

            $totals = OrderTotals::calculate($target->outlet, (int) $target->items()->sum('line_total'));
            $target->update(OrderWriter::totalsAttributes($totals, null, 0, null)
                + ['customer_id' => $target->customer_id ?? $source->customer_id, 'customer_name' => $target->customer_name ?? $source->customer_name]);

            $note = 'Digabung dengan '.$source->number.($source->table_number ? ' (meja '.$source->table_number.')' : '');
            OrderWriter::history($target, $target->status, $target->status, $user, $note);
            KitchenFlow::sync($target, $user);

            $from = $source->status;
            $source->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => 'Digabung ke '.$target->number,
                'subtotal' => 0, 'discount_amount' => 0, 'service_charge_amount' => 0, 'tax_amount' => 0, 'total' => 0,
            ]);
            OrderWriter::history($source, $from, OrderStatus::Cancelled, $user, 'Digabung ke '.$target->number);
            OrderWriter::releaseIfFree($source->diningTable);

            return $target;
        });
    }
}
