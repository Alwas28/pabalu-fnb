<?php

namespace App\Support\Pos;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Inventory\RecipeUsage;

/**
 * Titik kait setelah perubahan order yang berdampak ke modul lain (mis. stok bahan dari resep).
 * Dipanggil dari dalam transaksi DB aksi order.
 */
class OrderEvents
{
    /**
     * Item baru tercatat di order (pesanan baru / tambahan open bill).
     *
     * @param  iterable<OrderItem>  $items
     */
    public static function itemsAdded(Order $order, iterable $items): void
    {
        app(RecipeUsage::class)->consume($order, $items);
    }

    /** Order dibatalkan sebelum / sesudah dibayar: bahan yang terpakai dikembalikan ke stok. */
    public static function voided(Order $order): void
    {
        app(RecipeUsage::class)->reverse($order);
    }
}
