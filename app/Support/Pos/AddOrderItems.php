<?php

namespace App\Support\Pos;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Tambah item ke open bill (mis. pelanggan dine-in memesan lagi). Total dihitung ulang.
 */
class AddOrderItems
{
    public function __construct(private CartResolver $cart) {}

    /**
     * @param  array<string, mixed>  $input  items
     *
     * @throws ValidationException
     */
    public function handle(User $cashier, Order $order, array $input): Order
    {
        ShiftManager::require($cashier, $order->outlet);
        OrderWriter::ensureOpen($order);

        $data = Validator::make($input, CartResolver::rules(), CartResolver::messages())->validate();
        $lines = $this->cart->resolve($order->outlet, $data['items']);

        return DB::transaction(function () use ($cashier, $order, $lines) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            OrderWriter::ensureOpen($order);

            OrderWriter::writeLines($order, $lines);

            $totals = OrderTotals::calculate($order->outlet, (int) $order->items()->sum('line_total'));
            $order->update(OrderWriter::totalsAttributes($totals, null, 0, null));

            OrderWriter::history($order, $order->status, $order->status, $cashier, 'Tambah '.collect($lines)->sum('quantity').' item');
            KitchenFlow::sync($order, $cashier, 'Item tambahan masuk dapur');

            return $order;
        });
    }
}
