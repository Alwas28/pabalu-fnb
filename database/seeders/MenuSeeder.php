<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\MenuItem;
use App\Models\Outlet;
use Illuminate\Database\Seeder;

/**
 * Menu demo untuk "Pabalu Coffee & Kitchen": kategori, varian, modifier,
 * harga khusus outlet (Baubau) dan menu habis (Kolaka). Idempoten; tidak berjalan di production.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('MenuSeeder dilewati: data demo tidak boleh dibuat di production.');

            return;
        }

        $business = Business::where('name', 'Pabalu Coffee & Kitchen')->first();

        if (! $business) {
            return;
        }

        $categories = collect(['Kopi' => 1, 'Non-Kopi' => 2, 'Makanan' => 3, 'Camilan' => 4])
            ->map(fn (int $order, string $name) => $business->menuCategories()->updateOrCreate(['name' => $name], ['sort_order' => $order]));

        $groups = collect([
            'Level Gula' => [1, 1, ['Normal' => 0, 'Less Sugar' => 0, 'No Sugar' => 0]],
            'Suhu' => [1, 1, ['Dingin' => 0, 'Panas' => 0]],
            'Tambahan Kopi' => [0, 2, ['Extra Shot' => 5000, 'Oat Milk' => 6000, 'Sirup Hazelnut' => 4000]],
            'Level Pedas' => [1, 1, ['Tidak Pedas' => 0, 'Sedang' => 0, 'Pedas' => 0]],
            'Topping' => [0, null, ['Telur Ceplok' => 4000, 'Kerupuk' => 2000, 'Keju' => 5000]],
        ])->map(function (array $config, string $name) use ($business) {
            [$min, $max, $options] = $config;
            $group = $business->modifierGroups()->updateOrCreate(['name' => $name], ['min_select' => $min, 'max_select' => $max]);

            $position = 0;
            foreach ($options as $option => $price) {
                $group->modifiers()->updateOrCreate(['name' => $option], ['price' => $price, 'sort_order' => $position++]);
            }

            return $group;
        });

        $menu = [
            // [kategori, nama, sku, harga, varian => selisih, grup modifier]
            ['Kopi', 'Kopi Susu Gula Aren', 'KOP-001', 22000, ['Regular' => 0, 'Large' => 6000], ['Suhu', 'Level Gula', 'Tambahan Kopi']],
            ['Kopi', 'Americano', 'KOP-002', 18000, ['Regular' => 0, 'Large' => 5000], ['Suhu', 'Tambahan Kopi']],
            ['Kopi', 'Cappuccino', 'KOP-003', 24000, [], ['Suhu', 'Level Gula', 'Tambahan Kopi']],
            ['Non-Kopi', 'Matcha Latte', 'NKP-001', 26000, ['Regular' => 0, 'Large' => 6000], ['Suhu', 'Level Gula']],
            ['Non-Kopi', 'Es Teh Lemon', 'NKP-002', 12000, [], ['Level Gula']],
            ['Non-Kopi', 'Air Mineral', 'NKP-003', 6000, [], []],
            ['Makanan', 'Nasi Goreng Spesial', 'MKN-001', 28000, [], ['Level Pedas', 'Topping']],
            ['Makanan', 'Mie Ayam Bakso', 'MKN-002', 25000, [], ['Level Pedas', 'Topping']],
            ['Makanan', 'Ayam Geprek', 'MKN-003', 23000, [], ['Level Pedas']],
            ['Camilan', 'Croissant Cokelat', 'CML-001', 18000, [], []],
            ['Camilan', 'Kentang Goreng', 'CML-002', 15000, [], []],
            ['Camilan', 'Pisang Goreng Keju', 'CML-003', 16000, [], []],
        ];

        foreach ($menu as $position => [$category, $name, $sku, $price, $variants, $modifierGroups]) {
            $item = MenuItem::updateOrCreate(
                ['business_id' => $business->id, 'sku' => $sku],
                ['menu_category_id' => $categories[$category]->id, 'name' => $name, 'price' => $price, 'sort_order' => $position],
            );

            $variantOrder = 0;
            foreach ($variants as $variant => $adjustment) {
                $item->variants()->updateOrCreate(['name' => $variant], ['price_adjustment' => $adjustment, 'sort_order' => $variantOrder++]);
            }

            $item->modifierGroups()->sync(collect($modifierGroups)->values()
                ->mapWithKeys(fn (string $group, int $index) => [$groups[$group]->id => ['sort_order' => $index]])
                ->all());
        }

        $outlets = Outlet::where('business_id', $business->id)->get()->keyBy('code');
        $bySku = MenuItem::where('business_id', $business->id)->get()->keyBy('sku');

        // Harga berbeda per outlet: Baubau sedikit lebih murah untuk menu kopi.
        if ($baubau = $outlets->get('BAU')) {
            foreach (['KOP-001' => 20000, 'KOP-002' => 16000] as $sku => $price) {
                $bySku[$sku]->outlets()->syncWithoutDetaching([$baubau->id => ['price' => $price, 'is_available' => true]]);
            }
        }

        // Kolaka (grab-and-go) belum menjual makanan berat; croissant sedang habis.
        if ($kolaka = $outlets->get('KLK')) {
            foreach (['MKN-001', 'MKN-002', 'MKN-003', 'CML-001'] as $sku) {
                $bySku[$sku]->outlets()->syncWithoutDetaching([$kolaka->id => ['price' => null, 'is_available' => false]]);
            }
        }
    }
}
