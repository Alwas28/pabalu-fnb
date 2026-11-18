<?php

namespace App\Support\Pos;

use App\Enums\KitchenItemStatus;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Inventory\RecipeUsage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Hapus / kurangi item open bill (Blueprint Khusus F&B bagian 5: "hapus item dengan izin").
 *
 * - Hanya Supervisor ke atas, wajib alasan; tercatat di riwayat order.
 * - Porsi yang dihapus disimpan (voided_quantity) — item tidak hilang dari jejak audit.
 * - Total dihitung ulang, bahan resep dikembalikan, status dapur diperbarui.
 * - Item pesanan yang sudah dibayar memakai alur refund.
 */
class VoidOrderItem
{
    public function __construct(private RecipeUsage $recipes) {}

    /**
     * @param  array<string, mixed>  $input  quantity, reason
     *
     * @throws AuthorizationException|ValidationException
     */
    public function handle(User $user, Order $order, OrderItem $item, array $input): Order
    {
        if (! $user->hasPermission(Permission::ApproveTransactions)) {
            throw new AuthorizationException('Hanya supervisor ke atas yang boleh menghapus item pesanan.');
        }

        if ($item->order_id !== $order->id) {
            throw ValidationException::withMessages(['item' => 'Item tidak ada di pesanan ini.']);
        }

        OrderWriter::ensureOpen($order);

        $data = Validator::make($input, [
            'quantity' => ['required', 'integer', 'min:1', 'max:'.max(1, $item->quantity)],
            'reason' => ['required', 'string', 'max:255'],
        ], ['quantity.max' => 'Melebihi jumlah item ('.$item->quantity.').'], ['quantity' => 'jumlah', 'reason' => 'alasan'])->validate();

        if ($item->quantity === 0) {
            throw ValidationException::withMessages(['item' => 'Item ini sudah dihapus.']);
        }

        if ((int) $order->items()->sum('quantity') - (int) $data['quantity'] <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Ini item terakhir. Batalkan pesanan bila semua item tidak jadi dipesan.']);
        }

        return DB::transaction(function () use ($user, $order, $item, $data) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            OrderWriter::ensureOpen($order);
            $item = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            $remove = (int) $data['quantity'];
            $remaining = $item->quantity - $remove;
            $costPerPortion = $item->quantity > 0 ? $item->cost_amount / $item->quantity : 0;

            $this->recipes->returnPortions($order, $item, $remove);

            $item->update([
                'quantity' => $remaining,
                'voided_quantity' => $item->voided_quantity + $remove,
                'line_total' => $item->unit_price * $remaining,
                'cost_amount' => (int) round($costPerPortion * $remaining),
                'void_reason' => $data['reason'],
            ] + ($remaining === 0 ? ['kitchen_status' => KitchenItemStatus::Cancelled, 'kitchen_updated_at' => now()] : []));

            $totals = OrderTotals::calculate($order->outlet, (int) $order->items()->sum('line_total'));
            $order->update(OrderWriter::totalsAttributes($totals, null, 0, null));

            OrderWriter::history($order, $order->status, $order->status, $user, 'Hapus '.$remove.'× '.$item->displayName().': '.$data['reason']);
            KitchenFlow::sync($order, $user);

            return $order;
        });
    }
}
