<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Pos\PlaceOrder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Layar kasir web dan halaman pesanan. Perhitungan detail diuji di PlaceOrderTest.
 */
class PosScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->sole();
    }

    private function menuId(string $sku): int
    {
        return MenuItem::where('sku', $sku)->value('id');
    }

    public function test_pos_requires_pos_permission(): void
    {
        $this->actingAs($this->user('pabalu.kasir@gmail.com'))->get(route('pos'))->assertOk()->assertSee('Air Mineral');
        $this->actingAs($this->user('pabalu.admin@gmail.com'))->get(route('pos'))->assertForbidden();
    }

    public function test_kasir_completes_a_split_payment_sale(): void
    {
        $this->actingAs($this->user('pabalu.kasir@gmail.com'));
        $kopi = MenuItem::where('sku', 'KOP-001')->with('variants')->sole();

        $page = Volt::test('pages.pos.index')
            ->call('pick', $this->menuId('NKP-003'))
            ->call('pick', $this->menuId('NKP-003'))   // menu sederhana digabung → qty 2
            ->call('pick', $kopi->id)                    // menu bervarian membuka pemilih
            ->assertSet('pickerItemId', $kopi->id)
            ->call('confirmPick')                        // suhu & level gula belum dipilih
            ->assertHasErrors('picker')
            ->call('toggleModifier', Modifier::where('name', 'Dingin')->value('modifier_group_id'), Modifier::where('name', 'Dingin')->value('id'))
            ->call('toggleModifier', Modifier::where('name', 'Normal')->value('modifier_group_id'), Modifier::where('name', 'Normal')->value('id'))
            ->call('confirmPick')
            ->assertHasNoErrors();

        $this->assertCount(2, $page->get('cart'));
        $this->assertSame(2, $page->get('cart')[0]['quantity']);

        // (12.000 + 22.000) + service 5% (1.700) + pajak 10% (3.570) = 39.270
        $page->call('openPayment')
            ->assertSet('payments.0.method', 'cash')
            ->assertSet('payments.0.amount', '39270')
            ->call('addPayment')
            ->assertSet('payments.1.amount', '0')
            ->set('payments.0.amount', '20000')        // sisa otomatis pindah ke baris terakhir
            ->assertSet('payments.1.amount', '19270')
            ->set('payments.1.method', 'qris')
            ->call('checkout')
            ->assertHasNoErrors()
            ->assertSet('cart', []);

        $order = Order::with('payments')->sole();
        $this->assertSame(39270, $order->total);
        $this->assertSame(['cash', 'qris'], $order->payments->map(fn ($p) => $p->method->value)->all());
        $page->assertSet('lastOrderId', $order->id)->assertSee($order->number);
    }

    public function test_kasir_cannot_apply_discount_from_screen(): void
    {
        $this->actingAs($this->user('pabalu.kasir@gmail.com'));

        Volt::test('pages.pos.index')
            ->call('pick', $this->menuId('NKP-003'))
            ->set('discountType', 'percent')
            ->set('discountValue', '50')
            ->call('openPayment')
            ->assertSet('payments.0.amount', '6930') // diskon diabaikan: 6.000 + service 300 + pajak 630
            ->call('checkout');

        $this->assertSame(0, Order::sole()->discount_amount);
        $this->assertSame(6930, Order::sole()->total);
    }

    public function test_sold_out_menu_cannot_be_added(): void
    {
        $owner = $this->user('pabalu.owner@gmail.com');
        $owner->switchOutlet(Outlet::where('code', 'KLK')->sole());
        $this->actingAs($owner);

        Volt::test('pages.pos.index')
            ->call('pick', $this->menuId('CML-001'))
            ->assertSet('cart', [])
            ->assertDispatched('toast', type: 'error');
    }

    public function test_orders_page_only_lists_orders_from_accessible_outlets(): void
    {
        $water = ['menu_item_id' => $this->menuId('NKP-003'), 'quantity' => 1];
        $place = fn (string $email, string $code) => app(PlaceOrder::class)->handle(
            $this->user($email), Outlet::where('code', $code)->sole(),
            ['service_type' => 'takeaway', 'items' => [$water], 'payments' => [['method' => 'cash', 'amount' => 10000]]],
        );

        $kendari = $place('pabalu.kasir@gmail.com', 'KDI');
        $baubau = $place('pabalu.supervisor@gmail.com', 'BAU');

        $kasir = $this->user('pabalu.kasir@gmail.com');

        $this->actingAs($kasir)->get(route('orders.index', ['outlet' => 'all']))
            ->assertOk()
            ->assertSee($kendari->number)
            ->assertDontSee($baubau->number);

        $this->actingAs($kasir)->get(route('orders.show', $kendari))->assertOk()->assertSee('Air Mineral');
        $this->actingAs($kasir)->get(route('orders.show', $baubau))->assertForbidden();
    }
}
