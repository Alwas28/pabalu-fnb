<?php

namespace Database\Seeders;

use App\Enums\StockMovementType;
use App\Models\Business;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\RecipeItem;
use App\Models\StockMovement;
use App\Support\Inventory\StockLedger;
use Illuminate\Database\Seeder;

/**
 * Bahan baku, saldo awal stok per outlet & resep demo. Idempoten; tidak berjalan di production.
 * Outlet Kendari mengaktifkan pengurangan stok otomatis dari resep.
 */
class InventorySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $business = Business::where('name', 'Pabalu Coffee & Kitchen')->first();

        if (! $business) {
            return;
        }

        // nama => [satuan, satuan beli, isi, minimum, harga per satuan beli, saldo awal (satuan dasar)]
        $ingredients = collect([
            'Biji Kopi Arabika' => ['g', 'kg', 1000, 1000, 180000, 3000],
            'Susu UHT' => ['ml', 'liter', 1000, 5000, 18000, 12000],
            'Gula Aren Cair' => ['ml', 'liter', 1000, 1000, 45000, 2500],
            'Bubuk Matcha' => ['g', 'kg', 1000, 200, 400000, 500],
            'Teh Hitam' => ['g', 'kg', 1000, 200, 120000, 600],
            'Lemon' => ['pcs', null, null, 10, 2500, 30],
            'Air Mineral 600ml' => ['pcs', 'dus', 24, 24, 48000, 48],
            'Beras' => ['g', 'kg', 1000, 5000, 14000, 20000],
            'Telur' => ['pcs', 'tray', 30, 30, 54000, 90],
            'Minyak Goreng' => ['ml', 'liter', 1000, 2000, 19000, 6000],
            'Kentang Beku' => ['g', 'kg', 1000, 2000, 38000, 5000],
            'Keju Cheddar' => ['g', 'kg', 1000, 500, 120000, 1500],
            'Pisang' => ['pcs', 'sisir', 12, 12, 24000, 36],
        ])->map(function (array $config, string $name) use ($business) {
            [$unit, $purchaseUnit, $size, $min] = $config;

            return $business->ingredients()->updateOrCreate(['name' => $name], [
                'base_unit' => $unit,
                'purchase_unit' => $purchaseUnit,
                'purchase_unit_size' => $size,
                'min_stock' => $min,
            ]);
        });

        // Saldo awal tiap outlet (hanya bila outlet belum punya riwayat stok).
        $ledger = app(StockLedger::class);
        $specs = [
            'Biji Kopi Arabika' => [180000, 3000], 'Susu UHT' => [18000, 12000], 'Gula Aren Cair' => [45000, 2500],
            'Bubuk Matcha' => [400000, 500], 'Teh Hitam' => [120000, 600], 'Lemon' => [2500, 30],
            'Air Mineral 600ml' => [48000, 48], 'Beras' => [14000, 20000], 'Telur' => [54000, 90],
            'Minyak Goreng' => [19000, 6000], 'Kentang Beku' => [38000, 5000], 'Keju Cheddar' => [120000, 1500], 'Pisang' => [24000, 36],
        ];

        foreach ($business->outlets()->get() as $outlet) {
            if (StockMovement::where('outlet_id', $outlet->id)->exists()) {
                continue;
            }

            foreach ($specs as $name => [$purchasePrice, $opening]) {
                $ingredient = $ingredients[$name];
                $unitCost = $purchasePrice / (float) ($ingredient->purchase_unit_size ?: 1);
                $ledger->move($outlet, $ingredient, StockMovementType::Adjustment, $opening, $unitCost, note: 'Saldo awal');
            }
        }

        // Resep per porsi (satuan dasar). Baris dengan varian = tambahan bila varian dipilih.
        $recipes = [
            'KOP-001' => [['Biji Kopi Arabika', 18], ['Susu UHT', 150], ['Gula Aren Cair', 25], ['Susu UHT', 80, 'Large'], ['Biji Kopi Arabika', 9, 'Large']],
            'KOP-002' => [['Biji Kopi Arabika', 18], ['Biji Kopi Arabika', 9, 'Large']],
            'KOP-003' => [['Biji Kopi Arabika', 18], ['Susu UHT', 120]],
            'NKP-001' => [['Bubuk Matcha', 8], ['Susu UHT', 180], ['Susu UHT', 80, 'Large']],
            'NKP-002' => [['Teh Hitam', 5], ['Lemon', 0.5]],
            'NKP-003' => [['Air Mineral 600ml', 1]],
            'MKN-001' => [['Beras', 200], ['Telur', 1], ['Minyak Goreng', 20]],
            'CML-002' => [['Kentang Beku', 200], ['Minyak Goreng', 30]],
            'CML-003' => [['Pisang', 2], ['Keju Cheddar', 20], ['Minyak Goreng', 25]],
        ];

        foreach ($recipes as $sku => $rows) {
            $menuItem = MenuItem::where('business_id', $business->id)->where('sku', $sku)->with('variants')->first();

            if (! $menuItem || $menuItem->recipeItems()->exists()) {
                continue;
            }

            foreach ($rows as $row) {
                RecipeItem::create([
                    'menu_item_id' => $menuItem->id,
                    'menu_variant_id' => isset($row[2]) ? $menuItem->variants->firstWhere('name', $row[2])?->id : null,
                    'ingredient_id' => $ingredients[$row[0]]->id,
                    'quantity' => $row[1],
                ]);
            }
        }

        foreach (['Extra Shot' => ['Biji Kopi Arabika', 9], 'Keju' => ['Keju Cheddar', 15], 'Telur Ceplok' => ['Telur', 1]] as $modifierName => [$ingredient, $qty]) {
            $modifier = Modifier::whereHas('group', fn ($q) => $q->where('business_id', $business->id))->where('name', $modifierName)->first();

            if ($modifier && ! $modifier->recipeItems()->exists()) {
                RecipeItem::create(['modifier_id' => $modifier->id, 'ingredient_id' => $ingredients[$ingredient]->id, 'quantity' => $qty]);
            }
        }

        $business->outlets()->where('code', 'KDI')->update(['recipe_stock_deduction' => true]);
    }
}
