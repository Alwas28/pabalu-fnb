<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\ShiftManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboards_show_real_transactions(): void
    {
        $this->seed(DatabaseSeeder::class);
        $kasir = User::where('email', 'pabalu.kasir@gmail.com')->sole();
        $kendari = Outlet::where('code', 'KDI')->sole();

        $this->actingAs($kasir)->get('/dashboard')->assertOk()->assertSee('Shift belum dibuka');

        app(ShiftManager::class)->open($kasir, $kendari, ['opening_cash' => 150000]);
        $order = app(PlaceOrder::class)->handle($kasir, $kendari, [
            'service_type' => 'takeaway',
            'items' => [['menu_item_id' => MenuItem::where('sku', 'CML-002')->value('id'), 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ]);

        // 30.000 + service 1.500 + pajak 3.150 = 34.650
        $this->actingAs($kasir)->get('/dashboard')->assertOk()
            ->assertSee($order->number)
            ->assertSee('Rp 34.650')
            ->assertSee('Rp 184.650'); // kas ekspektasi

        $this->actingAs(User::where('email', 'pabalu.owner@gmail.com')->sole())->get('/dashboard')->assertOk()
            ->assertSee('Rp 30.000')       // penjualan bersih bulan ini
            ->assertSee('Kentang Goreng')  // menu terlaris
            ->assertSee('Outlet Kendari')
            ->assertDontSee('Data contoh');
    }
}
