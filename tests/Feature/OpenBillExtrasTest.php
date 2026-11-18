<?php

namespace Tests\Feature;

use App\Enums\DeliveryChannel;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Pos\MergeOrders;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\ShiftManager;
use App\Support\Pos\VoidOrderItem;
use App\Support\Receipt\EscPosReceipt;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Kendari: bayar nanti, status meja dilacak, stok berkurang dari resep (air mineral = 1 botol per porsi).
 */
class OpenBillExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private User $supervisor;

    private Outlet $kendari;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->kasir = User::where('email', 'pabalu.kasir@gmail.com')->sole();
        $this->supervisor = User::where('email', 'pabalu.supervisor@gmail.com')->sole();
        $this->kendari = Outlet::where('code', 'KDI')->sole();
        app(ShiftManager::class)->open($this->kasir, $this->kendari, ['opening_cash' => 0]);
        app(ShiftManager::class)->open($this->supervisor, $this->kendari, ['opening_cash' => 0]);
    }

    private function openBill(string $table, array $items): Order
    {
        return app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'dine_in', 'pay_later' => true,
            'dining_table_id' => $this->kendari->diningTables()->where('name', $table)->value('id'),
            'items' => $items,
        ]);
    }

    private function line(string $sku, int $quantity): array
    {
        return ['menu_item_id' => MenuItem::where('sku', $sku)->value('id'), 'quantity' => $quantity];
    }

    private function water(): float
    {
        return app(StockLedger::class)->quantity($this->kendari, Ingredient::where('name', 'Air Mineral 600ml')->sole());
    }

    private function assertInvalid(string $field, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Seharusnya ditolak pada [{$field}].");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_supervisor_reduces_item_with_reason_and_stock_returns(): void
    {
        $order = $this->openBill('A1', [$this->line('NKP-003', 3), $this->line('CML-002', 1)]);
        $water = $order->items()->where('name', 'Air Mineral')->sole();
        $this->assertEqualsWithDelta(45, $this->water(), 0.001);

        try {
            app(VoidOrderItem::class)->handle($this->kasir, $order, $water, ['quantity' => 1, 'reason' => 'x']);
            $this->fail('Kasir tidak boleh menghapus item.');
        } catch (AuthorizationException) {
        }

        $this->assertInvalid('reason', fn () => app(VoidOrderItem::class)->handle($this->supervisor, $order, $water, ['quantity' => 1, 'reason' => '']));

        app(VoidOrderItem::class)->handle($this->supervisor, $order, $water, ['quantity' => 2, 'reason' => 'Salah input']);

        $water->refresh();
        $order->refresh();
        $this->assertSame(1, $water->quantity);
        $this->assertSame(2, $water->voided_quantity);
        $this->assertSame(21000, $order->subtotal); // 6.000 + 15.000
        $this->assertEqualsWithDelta(47, $this->water(), 0.001);

        // Item yang tersisa boleh dihapus seluruhnya, tetapi item terakhir pesanan tidak.
        app(VoidOrderItem::class)->handle($this->supervisor, $order, $water, ['quantity' => 1, 'reason' => 'Tidak jadi']);
        $this->assertSame(KitchenItemStatus::Cancelled, $water->fresh()->kitchen_status);
        $fries = $order->items()->where('name', 'Kentang Goreng')->sole();
        $this->assertInvalid('quantity', fn () => app(VoidOrderItem::class)->handle($this->supervisor, $order->fresh(), $fries, ['quantity' => 1, 'reason' => 'x']));

        $this->assertStringNotContainsString('Air Mineral', (new EscPosReceipt($order->fresh()))->text());
    }

    public function test_merging_moves_items_and_closes_source_order(): void
    {
        $target = $this->openBill('A1', [$this->line('NKP-003', 1)]);
        $source = $this->openBill('A2', [$this->line('NKP-003', 2)]);

        app(MergeOrders::class)->handle($this->kasir, $target, $source);

        $target->refresh();
        $source->refresh();
        $this->assertSame(3, (int) $target->items()->sum('quantity'));
        $this->assertSame(18000, $target->subtotal);
        $this->assertSame(OrderStatus::Cancelled, $source->status);
        $this->assertSame('Digabung ke '.$target->number, $source->cancel_reason);
        $this->assertSame(0, $source->total);
        $this->assertFalse($this->kendari->diningTables()->where('name', 'A2')->sole()->isOccupied());
        $this->assertSame(0, StockMovement::where('reference_id', $source->id)->where('reference_type', $source->getMorphClass())->count());
        $this->assertSame(2, (int) $target->items()->max('kitchen_batch'));

        $this->assertInvalid('source_order_id', fn () => app(MergeOrders::class)->handle($this->kasir, $target, $target));
    }

    public function test_delivery_orders_capture_channel_and_contact(): void
    {
        $base = [
            'service_type' => 'delivery',
            'items' => [$this->line('NKP-003', 1)],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ];
        $place = fn (array $extra) => app(PlaceOrder::class)->handle($this->kasir, $this->kendari, $extra + $base);

        $this->assertInvalid('delivery_channel', fn () => $place([]));
        $this->assertInvalid('delivery_address', fn () => $place(['delivery_channel' => 'direct', 'customer_name' => 'Rina', 'customer_phone' => '0812']));

        $direct = $place(['delivery_channel' => 'direct', 'customer_name' => 'Rina', 'customer_phone' => '0812', 'delivery_address' => 'Jl. Melati 3']);
        $this->assertSame(DeliveryChannel::Direct, $direct->delivery_channel);
        $this->assertSame('Jl. Melati 3', $direct->delivery_address);

        $gofood = $place(['delivery_channel' => 'gofood', 'delivery_reference' => 'F-123']);
        $this->assertStringContainsString('GoFood #F-123', (new EscPosReceipt($gofood))->text());

        $this->assertInvalid('delivery_channel', fn () => app(PlaceOrder::class)->handle($this->kasir, $this->kendari, ['service_type' => 'takeaway', 'delivery_channel' => 'gofood'] + $base));
    }

    public function test_pos_screen_handles_delivery_void_and_merge(): void
    {
        $this->actingAs($this->kasir);
        Volt::test('pages.pos.index')
            ->set('serviceType', 'delivery')
            ->set('deliveryChannel', 'grabfood')
            ->set('deliveryReference', 'GF-77')
            ->call('pick', MenuItem::where('sku', 'NKP-003')->value('id'))
            ->call('openPayment')
            ->call('checkout')
            ->assertHasNoErrors();
        $this->assertSame('GF-77', Order::sole()->delivery_reference);

        $target = $this->openBill('B1', [$this->line('NKP-003', 2)]);
        $source = $this->openBill('B2', [$this->line('CML-002', 1)]);

        $this->actingAs($this->supervisor);
        Volt::test('pages.pos.index')
            ->call('loadOrder', $target->id)
            ->call('voidItem', $target->items()->value('id'), 1, 'Salah hitung')
            ->assertHasNoErrors()
            ->call('mergeOrder', $source->id)
            ->assertHasNoErrors();

        $this->assertSame(21000, $target->fresh()->subtotal); // 6.000 + 15.000
        $this->assertSame(OrderStatus::Cancelled, $source->fresh()->status);
    }

    public function test_api_void_and_merge_endpoints(): void
    {
        $target = $this->openBill('A3', [$this->line('NKP-003', 2), $this->line('CML-002', 1)]);
        $source = $this->openBill('A4', [$this->line('CML-002', 1)]);
        $water = $target->items()->where('name', 'Air Mineral')->sole();

        Sanctum::actingAs($this->kasir, ['pos']);
        $this->postJson("/api/v1/orders/{$target->id}/items/{$water->id}/void", ['quantity' => 1, 'reason' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/orders/{$target->id}/merge", ['source_order_id' => $source->id])->assertOk()->assertJsonPath('data.subtotal', 42000);

        Sanctum::actingAs($this->supervisor, ['pos']);
        $this->postJson("/api/v1/orders/{$target->id}/items/{$water->id}/void", ['quantity' => 1, 'reason' => 'Batal satu'])
            ->assertOk()
            ->assertJsonPath('data.subtotal', 36000);
    }
}
