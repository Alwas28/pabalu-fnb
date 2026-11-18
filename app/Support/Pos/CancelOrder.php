<?php

namespace App\Support\Pos;

use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Batalkan open bill yang belum dibayar. Hak sensitif: hanya Supervisor ke atas,
 * wajib dengan alasan, dan tercatat pelaku & waktunya (Blueprint Khusus F&B bagian 14).
 * Order yang sudah dibayar memakai alur void/refund (belum tersedia).
 */
class CancelOrder
{
    /**
     * @param  array<string, mixed>  $input  reason
     *
     * @throws AuthorizationException|ValidationException
     */
    public function handle(User $user, Order $order, array $input): Order
    {
        if (! $user->hasPermission(Permission::ApproveTransactions)) {
            throw new AuthorizationException('Hanya supervisor ke atas yang boleh membatalkan pesanan.');
        }

        OrderWriter::ensureOpen($order);

        $data = Validator::make($input, ['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan'])->validate();

        return DB::transaction(function () use ($user, $order, $data) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            OrderWriter::ensureOpen($order);
            $from = $order->status;

            $order->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $data['reason'],
            ]);

            $order->items()->update(['kitchen_status' => KitchenItemStatus::Cancelled, 'kitchen_updated_at' => now()]);
            OrderWriter::history($order, $from, OrderStatus::Cancelled, $user, $data['reason']);
            OrderWriter::releaseIfFree($order->diningTable);

            return $order;
        });
    }
}
