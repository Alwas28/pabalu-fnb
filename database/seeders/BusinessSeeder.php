<?php

namespace Database\Seeders;

use App\Enums\KitchenMode;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Bisnis F&B demo dengan 3 outlet berpengaturan berbeda, serta keanggotaan
 * akun demo dari UserSeeder. Idempotent; tidak berjalan di production.
 */
class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('BusinessSeeder dilewati: data demo tidak boleh dibuat di production.');

            return;
        }

        $accounts = collect(UserSeeder::accounts());
        $owner = User::where('email', $accounts->first()['email'])->firstOrFail();

        $business = Business::firstOrCreate(
            ['owner_id' => $owner->id, 'name' => 'Pabalu Coffee & Kitchen'],
            ['phone' => '0401-312345', 'address' => 'Jl. Ahmad Yani No. 10, Kendari', 'timezone' => 'Asia/Makassar'],
        );

        $outlets = collect($this->outlets())->mapWithKeys(fn (array $data, string $code) => [
            $code => Outlet::updateOrCreate(['business_id' => $business->id, 'code' => $code], $data),
        ]);

        foreach ($accounts as $account) {
            $user = User::where('email', $account['email'])->firstOrFail();

            $membership = BusinessUser::updateOrCreate(
                ['business_id' => $business->id, 'user_id' => $user->id],
                ['role' => $account['role'], 'is_active' => true],
            );

            $membership->outlets()->sync(
                $account['role']->coversAllOutlets() ? [] : $outlets->only($account['outlets'])->pluck('id')->all(),
            );

            // Outlet awal: outlet tugas pertama, atau Kendari untuk Owner/Admin.
            $firstOutlet = $account['outlets'][0] ?? 'KDI';

            $user->forceFill([
                'current_business_id' => $business->id,
                'current_outlet_id' => $user->current_outlet_id ?? $outlets[$firstOutlet]->id,
            ])->save();
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function outlets(): array
    {
        return [
            // Outlet lengkap: semua layanan, meja, layar dapur, pajak + service charge.
            'KDI' => [
                'name' => 'Outlet Kendari',
                'address' => 'Jl. Ahmad Yani No. 10, Kendari',
                'phone' => '0401-312345',
                'opens_at' => '08:00',
                'closes_at' => '22:00',
                'service_types' => [ServiceType::DineIn, ServiceType::Takeaway, ServiceType::Delivery],
                'tables_enabled' => true,
                'kitchen_mode' => KitchenMode::Display,
                'tax_enabled' => true,
                'tax_rate' => 10,
                'service_charge_enabled' => true,
                'service_charge_rate' => 5,
                'payment_methods' => PaymentMethod::cases(),
            ],
            // Kedai kecil: tanpa meja, cetak tiket, pajak saja.
            'BAU' => [
                'name' => 'Outlet Baubau',
                'address' => 'Jl. Sultan Hasanuddin No. 5, Baubau',
                'opens_at' => '09:00',
                'closes_at' => '21:00',
                'service_types' => [ServiceType::DineIn, ServiceType::Takeaway],
                'tables_enabled' => false,
                'kitchen_mode' => KitchenMode::Ticket,
                'tax_enabled' => true,
                'tax_rate' => 10,
                'payment_methods' => [PaymentMethod::Cash, PaymentMethod::Qris, PaymentMethod::DebitCard],
            ],
            // Grab-and-go: takeaway & delivery saja, tanpa pajak.
            'KLK' => [
                'name' => 'Outlet Kolaka',
                'address' => 'Jl. Pemuda No. 21, Kolaka',
                'opens_at' => '07:00',
                'closes_at' => '20:00',
                'service_types' => [ServiceType::Takeaway, ServiceType::Delivery],
                'tables_enabled' => false,
                'kitchen_mode' => KitchenMode::Ticket,
                'payment_methods' => [PaymentMethod::Cash, PaymentMethod::Qris, PaymentMethod::EWallet],
            ],
        ];
    }
}
