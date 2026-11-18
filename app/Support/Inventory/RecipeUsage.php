<?php

namespace App\Support\Inventory;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * Pemakaian bahan berdasarkan resep (diisi oleh modul Persediaan).
 */
class RecipeUsage
{
    /**
     * @param  iterable<OrderItem>  $items
     */
    public function consume(Order $order, iterable $items): void {}

    public function reverse(Order $order): void {}
}
