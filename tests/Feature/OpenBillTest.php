<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CashShift;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Pos\AddOrderItems;
use App\Support\Pos\CancelOrder;
use App\Support\Pos\MoveOrderTable;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\SettleOrder;
use App\Support\Pos\ShiftManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Dine-in bayar nanti (open bill) & status meja.
 * Kendari: bayar nanti aktif, meja A1–A6 & B1–B4, status meja dilacak. Baubau: bayar nanti nonaktif.
 */
class OpenBillTest extends TestCase
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

    private function table(string $name): DiningTable
    {
        return $this->kendari->diningTables()->where('name', $name)->sole();
    }

    /** @return list<array<string, int>> */
    private function water(int $quantity = 2): array
    {
        return [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => $quantity]];
    }

    private function openBill(string $table = 'A1', ?User $cashier = null): Order
    {
        return app(PlaceOrder::class)->handle($cashier ?? $this->kasir, $this->kendari, [
            'service_type' => 'dine_in',
            'pay_later' => true,
            'dining_table_id' => $this->table($table)->id,
            'items' => $this->water(),
        ]);
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

    public function test_pay_later_creates_open_bill_and_occupies_table(): void
    {
        $order = $this->openBill('A1');

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(13860, $order->total);
        $this->assertSame(0, $order->payments()->count());
        $this->assertTrue($order->isOpen());
        $this->assertTrue($this->table('A1')->isOccupied());
    }

    public function test_pay_later_follows_outlet_setting_and_needs_a_table(): void
    {
        $this->assertInvalid('pay_later', fn () => app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'takeaway', 'pay_later' => true, 'items' => $this->water(),
        ]));

        $this->assertInvalid('dining_table_id', fn () => app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'dine_in', 'pay_later' => true, 'items' => $this->water(),
        ]));

        $baubau = Outlet::where('code', 'BAU')->sole();
        app(ShiftManager::class)->open($this->supervisor, $baubau, ['opening_cash' => 0]);
        $this->assertInvalid('pay_later', fn () => app(PlaceOrder::class)->handle($this->supervisor, $baubau, [
            'service_type' => 'dine_in', 'pay_later' => true, 'customer_name' => 'Budi', 'items' => $this->water(),
        ]));

        $this->assertSame(0, Order::count());
    }

    public function test_table_with_open_bill_cannot_get_a_second_bill_when_status_is_tracked(): void
    {
        $this->openBill('A1');

        $this->assertInvalid('dining_table_id', fn () => $this->openBill('A1'));

        // Tanpa pelacakan status meja, satu meja boleh punya beberapa open bill.
        $this->kendari->update(['table_status_enabled' => false]);
        $this->openBill('A1');
        $this->assertSame(2, Order::open()->count());
    }

    public function test_items_added_to_open_bill_update_totals(): void
    {
        $order = $this->openBill();

        app(AddOrderItems::class)->handle($this->kasir, $order, ['items' => $this->water(1)]);

        $order->refresh();
        $this->assertSame(2, $order->items()->count());
        $this->assertSame(18000, $order->subtotal);
        $this->assertSame(20790, $order->total); // 18.000 + 900 + 1.890
    }

    public function test_settling_records_payment_in_the_settlers_shift_and_frees_table(): void
    {
        $order = $this->openBill('A2');
        $supervisorShift = CashShift::currentFor($this->supervisor, $this->kendari);

        app(SettleOrder::class)->handle($this->supervisor, $order, [
            'discount_type' => 'amount', 'discount_value' => 2000, 'discount_reason' => 'Pelanggan tetap',
            'payments' => [['method' => 'qris', 'amount' => 5000], ['method' => 'cash', 'amount' => 20000]],
        ]);

        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status); // lunas, masih di antrean dapur
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(11550, $order->total); // (12.000 − 2.000) + 500 + 1.050
        $this->assertSame(13450, $order->change_amount);
        $this->assertSame([$supervisorShift->id], $order->payments()->pluck('cash_shift_id')->unique()->values()->all());
        $this->assertNotSame($supervisorShift->id, $order->cash_shift_id); // dibuka di shift kasir
        $this->assertFalse($this->table('A2')->isOccupied());

        $this->assertInvalid('order', fn () => app(SettleOrder::class)->handle($this->kasir, $order, ['payments' => [['method' => 'cash', 'amount' => 20000]]]));
        $this->assertInvalid('order', fn () => app(AddOrderItems::class)->handle($this->kasir, $order, ['items' => $this->water(1)]));
    }

    public function test_only_supervisor_can_cancel_open_bill_with_reason(): void
    {
        $order = $this->openBill('A3');

        try {
            app(CancelOrder::class)->handle($this->kasir, $order, ['reason' => 'Batal']);
            $this->fail('Kasir seharusnya tidak boleh membatalkan.');
        } catch (AuthorizationException) {
        }

        $this->assertInvalid('reason', fn () => app(CancelOrder::class)->handle($this->supervisor, $order, ['reason' => '']));

        app(CancelOrder::class)->handle($this->supervisor, $order, ['reason' => 'Pelanggan pergi']);

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('Pelanggan pergi', $order->cancel_reason);
        $this->assertTrue($order->canceller->is($this->supervisor));
        $this->assertFalse($this->table('A3')->isOccupied());
    }

    public function test_moving_open_bill_to_another_table(): void
    {
        $order = $this->openBill('A4');
        $this->openBill('A5');

        $this->assertInvalid('dining_table_id', fn () => app(MoveOrderTable::class)->handle($this->kasir, $order, $this->table('A5')->id));

        app(MoveOrderTable::class)->handle($this->kasir, $order, $this->table('B1')->id);

        $this->assertSame('B1', $order->fresh()->table_number);
        $this->assertFalse($this->table('A4')->isOccupied());
        $this->assertTrue($this->table('B1')->isOccupied());
    }
}
