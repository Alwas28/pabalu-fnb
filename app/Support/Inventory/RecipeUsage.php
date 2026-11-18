<?php

namespace App\Support\Inventory;

use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RecipeItem;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * Pemakaian bahan berdasarkan resep.
 *
 * - Kebutuhan bahan per porsi = resep dasar menu + tambahan varian terpilih + tambahan modifier terpilih.
 * - Estimasi biaya bahan (cost_amount) selalu dicatat di item order bila resep ada, memakai HPP rata-rata
 *   outlet saat transaksi — untuk food cost & laba kotor.
 * - Stok bahan baru benar-benar dikurangi bila outlet mengaktifkan "kurangi stok dari resep".
 * - Order yang dibatalkan / di-void mengembalikan bahan yang sudah terpakai.
 */
class RecipeUsage
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @param  iterable<OrderItem>  $items
     */
    public function consume(Order $order, iterable $items): void
    {
        $outlet = $order->outlet;
        $deduct = (bool) $outlet->recipe_stock_deduction;
        $costs = $this->averageCosts($outlet->id);

        foreach ($items as $item) {
            $needs = $this->requirementsFor($item);

            if ($needs->isEmpty()) {
                continue;
            }

            $cost = $needs->sum(fn (array $need) => $need['quantity'] * ($costs[$need['ingredient']->id] ?? 0));
            $item->forceFill(['cost_amount' => (int) round($cost)])->save();

            if ($deduct) {
                foreach ($needs as $need) {
                    $this->ledger->move($outlet, $need['ingredient'], StockMovementType::RecipeUsage, -$need['quantity'],
                        reference: $order, note: $item->quantity.'× '.$item->displayName());
                }
            }
        }
    }

    /**
     * Sebagian porsi item dihapus dari open bill: kembalikan bahannya bila stok dikurangi otomatis.
     */
    public function returnPortions(Order $order, OrderItem $item, int $quantity): void
    {
        if (! $order->outlet->recipe_stock_deduction) {
            return;
        }

        $used = StockMovement::where('reference_type', $order->getMorphClass())->where('reference_id', $order->id)
            ->where('type', StockMovementType::RecipeUsage)->exists();

        if (! $used) {
            return;
        }

        foreach ($this->requirementsFor($item, $quantity) as $need) {
            $this->ledger->move($order->outlet, $need['ingredient'], StockMovementType::RecipeReturn, $need['quantity'],
                $this->ledger->averageCost($order->outlet, $need['ingredient']), $order, 'Hapus '.$quantity.'× '.$item->displayName());
        }
    }

    /** Kembalikan bahan yang terpakai oleh order (dipanggil saat batal / void). */
    public function reverse(Order $order): void
    {
        $net = StockMovement::where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->id)
            ->whereIn('type', [StockMovementType::RecipeUsage, StockMovementType::RecipeReturn])
            ->selectRaw('ingredient_id, sum(quantity) as qty, max(unit_cost) as cost')
            ->groupBy('ingredient_id')
            ->get();

        foreach ($net as $row) {
            if ((float) $row->qty < 0) {
                $this->ledger->move($order->outlet, Ingredient::findOrFail($row->ingredient_id), StockMovementType::RecipeReturn,
                    -(float) $row->qty, (float) $row->cost, $order, 'Pembatalan '.$order->number);
            }
        }
    }

    /**
     * Kebutuhan bahan untuk satu item order (sudah dikali jumlah porsi).
     *
     * @return Collection<int, array{ingredient: Ingredient, quantity: float}>
     */
    public function requirementsFor(OrderItem $item, ?int $quantity = null): Collection
    {
        $portions = $quantity ?? $item->quantity;
        $modifierIds = $item->modifiers()->pluck('modifier_id')->filter()->all();

        return RecipeItem::query()
            ->with('ingredient')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('menu_item_id', $item->menu_item_id)->whereNull('menu_variant_id')->whereNull('modifier_id'))
                ->orWhere(fn ($q) => $q->where('menu_item_id', $item->menu_item_id)->where('menu_variant_id', $item->menu_variant_id ?? 0))
                ->orWhereIn('modifier_id', $modifierIds ?: [0]))
            ->get()
            ->groupBy('ingredient_id')
            ->map(fn (Collection $rows) => [
                'ingredient' => $rows->first()->ingredient,
                'quantity' => round($rows->sum(fn ($r) => (float) $r->quantity) * $portions, 3),
            ])
            ->values();
    }

    /**
     * Estimasi biaya bahan satu porsi menu (tanpa varian/modifier, atau dengan varian tertentu) di outlet.
     */
    public function portionCost(MenuItem $menuItem, int $outletId, ?int $variantId = null): int
    {
        $costs = $this->averageCosts($outletId);

        return (int) round(RecipeItem::where('menu_item_id', $menuItem->id)
            ->where(fn ($q) => $q->whereNull('menu_variant_id')->when($variantId, fn ($q) => $q->orWhere('menu_variant_id', $variantId)))
            ->whereNull('modifier_id')
            ->get()
            ->sum(fn ($r) => (float) $r->quantity * ($costs[$r->ingredient_id] ?? 0)));
    }

    /** @return array<int, float> ingredient_id → HPP rata-rata */
    private function averageCosts(int $outletId): array
    {
        return IngredientStock::where('outlet_id', $outletId)->pluck('avg_cost', 'ingredient_id')->map(fn ($v) => (float) $v)->all();
    }
}
