<?php

namespace Tests\Feature;

use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Pos\AddOrderItems;
use App\Support\Pos\KitchenFlow;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\SettleOrder;
use App\Support\Pos\ShiftManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Kendari: layar dapur (tahap lengkap). Baubau: cetak tiket.
 */
class KitchenTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private User $dapur;

    private Outlet $kendari;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->kasir = User::where('email', 'pabalu.kasir@gmail.com')->sole();
        $this->dapur = User::where('email', 'pabalu.dapur@gmail.com')->sole();
        $this->kendari = Outlet::where('code', 'KDI')->sole();
        app(ShiftManager::class)->open($this->kasir, $this->kendari, ['opening_cash' => 0]);
    }

    private function items(int $quantity = 1): array
    {
        return [
            ['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => $quantity],
            ['menu_item_id' => MenuItem::where('sku', 'CML-002')->value('id'), 'quantity' => 1],
        ];
    }

    private function paidOrder(): Order
    {
        return app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'takeaway', 'items' => $this->items(), 'payments' => [['method' => 'cash', 'amount' => 100000]],
        ]);
    }

    private function openBill(): Order
    {
        return app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'dine_in', 'pay_later' => true,
            'dining_table_id' => $this->kendari->diningTables()->where('name', 'A1')->value('id'),
            'items' => $this->items(),
        ]);
    }

    public function test_stages_follow_outlet_settings(): void
    {
        $this->assertSame(['queued', 'preparing', 'ready', 'served'], array_map(fn ($s) => $s->value, $this->kendari->kitchenStages()));

        $this->kendari->update(['kitchen_use_preparing' => false, 'kitchen_use_ready' => false]);
        $this->assertSame(['queued', 'served'], array_map(fn ($s) => $s->value, $this->kendari->kitchenStages()));
        $this->assertSame(KitchenItemStatus::Served, KitchenFlow::next($this->kendari, KitchenItemStatus::Queued));
        // Item yang sudah "Dimasak" sebelum tahap dimatikan tetap bisa maju.
        $this->assertSame(KitchenItemStatus::Served, KitchenFlow::next($this->kendari, KitchenItemStatus::Preparing));
    }

    public function test_paid_order_completes_when_every_item_is_served(): void
    {
        $order = $this->paidOrder();
        [$water, $fries] = $order->items()->get()->all();
        $flow = app(KitchenFlow::class);

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(KitchenItemStatus::Queued, $water->kitchen_status);

        $flow->advanceItem($this->dapur, $water);
        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);

        $flow->advanceItem($this->dapur, $water->fresh()); // siap
        $flow->advanceOrder($this->dapur, $order->fresh()); // fries: antre → dimasak (ikut item paling depan + 1)
        $this->assertSame(KitchenItemStatus::Preparing, $fries->fresh()->kitchen_status);

        $flow->advanceOrder($this->dapur, $order->fresh()); // semua siap
        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);

        $flow->advanceOrder($this->dapur, $order->fresh()); // semua tersaji
        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->completed_at);
        $this->assertSame(OrderStatus::Completed, $order->statusHistories()->reorder('id', 'desc')->first()->to_status);
    }

    public function test_open_bill_is_served_then_completed_on_payment(): void
    {
        $order = $this->openBill();
        $flow = app(KitchenFlow::class);
        $this->kendari->update(['kitchen_use_preparing' => false, 'kitchen_use_ready' => false]);

        $flow->advanceOrder($this->dapur, $order);
        $this->assertSame(OrderStatus::Served, $order->fresh()->status);

        // Tambahan pesanan kembali ke dapur sebagai batch 2.
        app(AddOrderItems::class)->handle($this->kasir, $order->fresh(), ['items' => [$this->items()[0]]]);
        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status); // sebagian tersaji, tambahan masih antre
        $this->assertSame(2, (int) $order->items()->max('kitchen_batch'));

        $flow->advanceOrder($this->dapur, $order->fresh());
        app(SettleOrder::class)->handle($this->kasir, $order->fresh(), ['payments' => [['method' => 'cash', 'amount' => 100000]]]);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_ticket_mode_outlet_skips_the_queue(): void
    {
        $baubau = Outlet::where('code', 'BAU')->sole();
        $supervisor = User::where('email', 'pabalu.supervisor@gmail.com')->sole();
        app(ShiftManager::class)->open($supervisor, $baubau, ['opening_cash' => 0]);

        $order = app(PlaceOrder::class)->handle($supervisor, $baubau, [
            'service_type' => 'takeaway', 'items' => $this->items(), 'payments' => [['method' => 'cash', 'amount' => 100000]],
        ]);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertTrue($order->items()->get()->every(fn ($i) => $i->kitchen_status === KitchenItemStatus::Served));
    }

    public function test_kitchen_screen_lists_and_advances_tickets(): void
    {
        $order = $this->paidOrder();
        $water = $order->items()->first();

        $this->actingAs($this->kasir)->get(route('kitchen.index'))->assertForbidden();
        $this->actingAs($this->dapur)->get(route('kitchen.index'))->assertOk()->assertSee($order->number);

        Volt::test('pages.kitchen.index')
            ->assertSee('Air Mineral')
            ->call('advanceItem', $water->id)    // air: antre → dimasak
            ->call('advanceOrder', $order->id);  // kentang menyusul ke "dimasak"

        $this->assertSame(KitchenItemStatus::Preparing, $water->fresh()->kitchen_status);
        $this->assertTrue($order->items()->get()->every(fn ($i) => $i->kitchen_status === KitchenItemStatus::Preparing));
    }

    public function test_kitchen_cannot_touch_other_outlets_tickets(): void
    {
        $baubau = Outlet::where('code', 'BAU')->sole();
        $baubau->update(['kitchen_mode' => 'display']);
        $supervisor = User::where('email', 'pabalu.supervisor@gmail.com')->sole();
        app(ShiftManager::class)->open($supervisor, $baubau, ['opening_cash' => 0]);
        $foreign = app(PlaceOrder::class)->handle($supervisor, $baubau, [
            'service_type' => 'takeaway', 'items' => $this->items(), 'payments' => [['method' => 'cash', 'amount' => 100000]],
        ]);

        $this->actingAs($this->dapur); // ditugaskan ke Kendari saja

        Volt::test('pages.kitchen.index')->call('advanceItem', $foreign->items()->first()->id)->assertNotFound();
        Volt::test('pages.kitchen.index')->call('advanceOrder', $foreign->id)->assertNotFound();
        $this->assertSame(KitchenItemStatus::Queued, $foreign->items()->first()->kitchen_status);
    }

    public function test_kitchen_ticket_prints_only_the_requested_batch(): void
    {
        $order = $this->openBill();
        app(AddOrderItems::class)->handle($this->kasir, $order, ['items' => [['menu_item_id' => MenuItem::where('sku', 'CML-003')->value('id'), 'quantity' => 2]]]);

        $this->actingAs($this->kasir)->get(route('orders.kitchen-ticket', ['order' => $order, 'batch' => 2, 'print' => 0]))
            ->assertOk()
            ->assertSee('TAMBAHAN #1')
            ->assertSee('Pisang Goreng Keju')
            ->assertDontSee('Air Mineral')
            ->assertDontSee('Rp');

        Sanctum::actingAs($this->kasir, ['pos']);
        $text = $this->getJson("/api/v1/orders/{$order->id}/kitchen-ticket?batch=1")->assertOk()->assertJsonPath('data.batch', 1)->json('data.text');
        $this->assertStringContainsString('MEJA A1', $text);
        $this->assertStringContainsString('1x Air Mineral', $text);
        $this->assertStringNotContainsString('Pisang', $text);
    }

    public function test_ready_to_serve_menu_skips_the_kitchen_screen(): void
    {
        MenuItem::where('sku', 'NKP-003')->update(['needs_kitchen' => false]); // air mineral botol
        $water = ['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => 2];

        // Hanya air mineral: langsung tersaji & selesai, tidak tampil di layar dapur.
        $waterOnly = app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'takeaway', 'items' => [$water], 'payments' => [['method' => 'cash', 'amount' => 50000]],
        ]);
        $this->assertSame(OrderStatus::Completed, $waterOnly->fresh()->status);
        $this->assertFalse($waterOnly->items()->sole()->sent_to_kitchen);

        // Campuran: hanya kentang goreng yang masuk antrean dapur.
        $mixed = $this->paidOrder();
        $items = $mixed->items()->get()->keyBy('name');
        $this->assertSame(KitchenItemStatus::Served, $items['Air Mineral']->kitchen_status);
        $this->assertSame(KitchenItemStatus::Queued, $items['Kentang Goreng']->kitchen_status);
        $this->assertSame(OrderStatus::Confirmed, $mixed->fresh()->status);

        $this->actingAs($this->dapur);
        Volt::test('pages.kitchen.index')
            ->assertDontSee($waterOnly->number)
            ->assertSee($mixed->number)
            ->assertSee('Kentang Goreng');
    }

    public function test_kitchen_ticket_leaves_out_ready_to_serve_menu(): void
    {
        MenuItem::where('sku', 'NKP-003')->update(['needs_kitchen' => false]);
        $order = $this->openBill();

        Sanctum::actingAs($this->kasir, ['pos']);
        $text = $this->getJson("/api/v1/orders/{$order->id}/kitchen-ticket?batch=1")->assertOk()->json('data.text');
        $this->assertStringContainsString('Kentang Goreng', $text);
        $this->assertStringNotContainsString('Air Mineral', $text);

        // Kiriman tambahan yang hanya air mineral: tidak ada tiket dapur.
        app(AddOrderItems::class)->handle($this->kasir, $order, ['items' => [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => 1]]]);
        $this->getJson("/api/v1/orders/{$order->id}/kitchen-ticket?batch=2")->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->actingAs($this->kasir)->get(route('orders.kitchen-ticket', ['order' => $order, 'batch' => 2, 'print' => 0]))->assertNotFound();
    }
}
