<?php

namespace App\Support\Inventory;

use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya jalur perubahan stok bahan (Blueprint Khusus F&B bagian 8).
 *
 * - Setiap perubahan menulis satu baris stock_movements beserta saldo sesudahnya.
 * - Barang masuk dengan biaya (pembelian, transfer masuk) memperbarui HPP rata-rata tertimbang;
 *   barang keluar dinilai dengan HPP rata-rata saat itu.
 * - Stok boleh negatif (penjualan tidak diblokir); laporan menandai stok minus untuk ditindaklanjuti.
 */
class StockLedger
{
    /**
     * @param  float  $quantity  satuan dasar; positif = masuk, negatif = keluar
     * @param  float|null  $unitCost  rupiah per satuan dasar untuk barang masuk; null = pakai HPP rata-rata
     */
    public function move(
        Outlet $outlet,
        Ingredient $ingredient,
        StockMovementType $type,
        float $quantity,
        ?float $unitCost = null,
        ?Model $reference = null,
        ?string $note = null,
        ?User $user = null,
    ): StockMovement {
        return DB::transaction(function () use ($outlet, $ingredient, $type, $quantity, $unitCost, $reference, $note, $user) {
            $stock = $this->lockedStock($outlet, $ingredient);
            $current = (float) $stock->quantity;
            $avg = (float) $stock->avg_cost;

            if ($quantity > 0 && $unitCost !== null) {
                // Rata-rata tertimbang hanya atas stok yang ada (stok minus tidak ikut menimbang).
                $base = max(0.0, $current);
                $avg = ($base + $quantity) > 0 ? (($base * $avg) + ($quantity * $unitCost)) / ($base + $quantity) : $unitCost;
            }

            $balance = round($current + $quantity, 3);
            $stock->update(['quantity' => $balance, 'avg_cost' => round($avg, 4)]);

            return StockMovement::create([
                'business_id' => $outlet->business_id,
                'outlet_id' => $outlet->id,
                'ingredient_id' => $ingredient->id,
                'type' => $type,
                'quantity' => round($quantity, 3),
                // Barang keluar dinilai dengan HPP rata-rata (tidak berubah oleh pengeluaran).
                'unit_cost' => round($quantity > 0 && $unitCost !== null ? $unitCost : $avg, 4),
                'balance_after' => $balance,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'note' => $note,
                'user_id' => $user?->id,
            ]);
        });
    }

    /** HPP rata-rata bahan di outlet (rupiah per satuan dasar). */
    public function averageCost(Outlet $outlet, Ingredient $ingredient): float
    {
        return (float) IngredientStock::where('outlet_id', $outlet->id)->where('ingredient_id', $ingredient->id)->value('avg_cost');
    }

    public function quantity(Outlet $outlet, Ingredient $ingredient): float
    {
        return (float) IngredientStock::where('outlet_id', $outlet->id)->where('ingredient_id', $ingredient->id)->value('quantity');
    }

    private function lockedStock(Outlet $outlet, Ingredient $ingredient): IngredientStock
    {
        IngredientStock::firstOrCreate(['outlet_id' => $outlet->id, 'ingredient_id' => $ingredient->id]);

        return IngredientStock::where('outlet_id', $outlet->id)->where('ingredient_id', $ingredient->id)->lockForUpdate()->firstOrFail();
    }
}
