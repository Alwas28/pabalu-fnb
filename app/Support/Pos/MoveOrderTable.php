<?php

namespace App\Support\Pos;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pindahkan open bill ke meja lain di outlet yang sama.
 * Bila status meja dilacak, meja tujuan tidak boleh sedang punya open bill.
 */
class MoveOrderTable
{
    /**
     * @throws ValidationException
     */
    public function handle(User $cashier, Order $order, int $tableId): Order
    {
        ShiftManager::require($cashier, $order->outlet);
        OrderWriter::ensureOpen($order);

        $target = $order->outlet->diningTables()->where('is_active', true)->find($tableId);

        if (! $target) {
            throw ValidationException::withMessages(['dining_table_id' => 'Meja tidak ditemukan di outlet ini.']);
        }

        if ($target->id === $order->dining_table_id) {
            return $order;
        }

        if ($order->outlet->tracksTableStatus() && $target->openOrders()->exists()) {
            throw ValidationException::withMessages(['dining_table_id' => "Meja {$target->name} masih punya pesanan terbuka."]);
        }

        return DB::transaction(function () use ($cashier, $order, $target) {
            /** @var ?DiningTable $previous */
            $previous = $order->diningTable;

            $order->update(['dining_table_id' => $target->id, 'table_number' => $target->name]);

            OrderWriter::history($order, $order->status, $order->status, $cashier, 'Pindah meja '.($previous?->name ?? '-').' → '.$target->name);
            OrderWriter::occupy($target);
            OrderWriter::releaseIfFree($previous);

            return $order;
        });
    }
}
