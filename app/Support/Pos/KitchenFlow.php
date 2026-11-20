<?php

namespace App\Support\Pos;

use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur dapur & status pemenuhan order.
 *
 * - Outlet dengan layar dapur: item baru masuk "Antre", lalu maju sesuai tahap outlet
 *   (Outlet::kitchenStages) sampai "Disajikan".
 * - Outlet tanpa layar dapur (cetak tiket): item langsung dianggap tersaji.
 * - Status order diturunkan dari item: Dikonfirmasi → Disiapkan → Siap → Disajikan,
 *   dan Selesai bila semua item tersaji DAN order lunas.
 */
class KitchenFlow
{
    /**
     * Status awal item baru. Menu yang tidak perlu diproses dapur (mis. air mineral botol)
     * langsung dianggap tersaji, sehingga tidak muncul di layar dapur.
     */
    public static function initialItemStatus(Outlet $outlet, bool $needsKitchen = true): KitchenItemStatus
    {
        return $needsKitchen && $outlet->usesKitchenDisplay() ? KitchenItemStatus::Queued : KitchenItemStatus::Served;
    }

    /** Tahap berikutnya sesuai tahap yang dipakai outlet; null bila sudah terakhir. */
    public static function next(Outlet $outlet, KitchenItemStatus $status): ?KitchenItemStatus
    {
        $stages = $outlet->kitchenStages();
        $index = array_search($status, $stages, true);

        // Tahap yang dimatikan setelah item berada di sana: lanjutkan ke tahap aktif berikutnya.
        if ($index === false) {
            $order = array_flip(array_map(fn ($s) => $s->value, KitchenItemStatus::cases()));
            foreach ($stages as $stage) {
                if ($order[$stage->value] > $order[$status->value]) {
                    return $stage;
                }
            }

            return null;
        }

        return $stages[$index + 1] ?? null;
    }

    /**
     * Majukan satu item ke tahap berikutnya.
     *
     * @throws ValidationException
     */
    public function advanceItem(User $user, OrderItem $item): OrderItem
    {
        return DB::transaction(function () use ($user, $item) {
            $item = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $order = $item->order;
            $next = $item->kitchen_status->isActive() ? self::next($order->outlet, $item->kitchen_status) : null;

            if (! $next) {
                throw ValidationException::withMessages(['item' => 'Item ini sudah selesai di dapur.']);
            }

            $item->update(['kitchen_status' => $next, 'kitchen_updated_at' => now()]);
            self::sync($order, $user);

            return $item;
        });
    }

    /** Majukan semua item aktif sebuah order satu tahap (tombol per tiket). */
    public function advanceOrder(User $user, Order $order): Order
    {
        return DB::transaction(function () use ($user, $order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $items = $order->items()->get()->filter(fn (OrderItem $i) => $i->kitchen_status->isActive());

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['order' => 'Semua item sudah tersaji.']);
            }

            // Item paling belakang ikut maju ke tahap item paling depan + 1, sehingga tiket bergerak bersama.
            $stages = $order->outlet->kitchenStages();
            $lowest = $items->map(fn ($i) => array_search($i->kitchen_status, $stages, true))->filter(fn ($v) => $v !== false)->min() ?? 0;
            $target = $stages[min($lowest + 1, count($stages) - 1)];

            foreach ($items as $item) {
                if (array_search($item->kitchen_status, $stages, true) < array_search($target, $stages, true)) {
                    $item->update(['kitchen_status' => $target, 'kitchen_updated_at' => now()]);
                }
            }

            self::sync($order, $user);

            return $order;
        });
    }

    /**
     * Turunkan status order dari status item & pembayaran, dan catat riwayat bila berubah.
     */
    public static function sync(Order $order, ?User $user = null, ?string $note = null): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            return;
        }

        $items = $order->items()->get(['kitchen_status', 'sent_to_kitchen'])
            ->reject(fn ($i) => $i->kitchen_status === KitchenItemStatus::Cancelled);

        // Status ditentukan oleh item dapur; menu siap saji (mis. air mineral) hanya dipakai bila
        // pesanan sama sekali tidak punya item dapur.
        $kitchenItems = $items->where('sent_to_kitchen', true);
        $statuses = ($kitchenItems->isNotEmpty() ? $kitchenItems : $items)->pluck('kitchen_status');

        $paid = in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true);

        $status = match (true) {
            $statuses->every(fn ($s) => $s === KitchenItemStatus::Served) => $paid ? OrderStatus::Completed : OrderStatus::Served,
            $statuses->every(fn ($s) => in_array($s, [KitchenItemStatus::Ready, KitchenItemStatus::Served], true)) => OrderStatus::Ready,
            $statuses->every(fn ($s) => $s === KitchenItemStatus::Queued) => OrderStatus::Confirmed,
            default => OrderStatus::Preparing,
        };

        if ($status === $order->status) {
            return;
        }

        $from = $order->status;
        $order->forceFill([
            'status' => $status,
            'completed_at' => $status === OrderStatus::Completed ? ($order->completed_at ?? now()) : null,
        ])->save();

        OrderWriter::history($order, $from, $status, $user, $note);
    }
}
