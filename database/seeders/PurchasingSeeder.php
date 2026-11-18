<?php

namespace Database\Seeders;

use App\Models\Business;
use Illuminate\Database\Seeder;

/** Supplier demo. Idempoten; tidak berjalan di production. */
class PurchasingSeeder extends Seeder
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

        $suppliers = [
            ['name' => 'CV Kopi Nusantara', 'contact_name' => 'Pak Rahmat', 'phone' => '0812-4000-1111', 'notes' => 'Biji kopi & matcha. Termin 14 hari.'],
            ['name' => 'UD Sumber Susu', 'contact_name' => 'Bu Wati', 'phone' => '0813-4000-2222', 'notes' => 'Susu UHT, kirim tiap Senin & Kamis.'],
            ['name' => 'Toko Sembako Makmur', 'contact_name' => 'Ko Hendra', 'phone' => '0401-320000', 'notes' => 'Beras, minyak, telur, gula.'],
        ];

        foreach ($suppliers as $supplier) {
            $business->suppliers()->updateOrCreate(['name' => $supplier['name']], $supplier);
        }
    }
}
