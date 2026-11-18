<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashShift;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\ShiftManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Aturan transaksi kasir: harga dari server, pajak/service per outlet, split payment, diskon & penomoran.
 * Data demo: KDI (pajak 10% + service 5%), BAU (pajak 10%, harga kopi khusus), KLK (tanpa pajak, makanan tidak dijual).
 */
class PlaceOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->kasir = $this->user('pabalu.kasir@gmail.com');
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->sole();
    }

    private function outlet(string $code): Outlet
    {
        return Outlet::where('code', $code)->sole();
    }

    private function menu(string $sku): MenuItem
    {
        return MenuItem::where('sku', $sku)->sole();
    }

    private function modifier(string $name): int
    {
        return Modifier::where('name', $name)->sole()->id;
    }

    /**
     * Kopi Susu Gula Aren Large + Dingin + Normal + Extra Shot.
     *
     * @return array<string, mixed>
     */
    private function kopiSusuLarge(int $quantity = 1): array
    {
        $item = $this->menu('KOP-001');

        return [
            'menu_item_id' => $item->id,
            'variant_id' => $item->variants()->where('name', 'Large')->value('id'),
            'modifier_ids' => [$this->modifier('Dingin'), $this->modifier('Normal'), $this->modifier('Extra Shot')],
            'quantity' => $quantity,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function place(string $outletCode, array $items, array $payments, array $overrides = [], ?User $cashier = null): Order
    {
        $cashier ??= $this->kasir;
        $outlet = $this->outlet($outletCode);
        CashShift::currentFor($cashier, $outlet) ?? app(ShiftManager::class)->open($cashier, $outlet, ['opening_cash' => 0]);

        return app(PlaceOrder::class)->handle($cashier, $outlet, $overrides + [
            'service_type' => 'takeaway',
            'items' => $items,
            'payments' => $payments,
        ]);
    }

    private function assertRejected(string $field, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Order seharusnya ditolak pada [{$field}].");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }

        $this->assertSame(0, Order::count());
    }

    public function test_totals_apply_outlet_service_charge_then_tax(): void
    {
        $water = ['menu_item_id' => $this->menu('NKP-003')->id, 'quantity' => 2];

        $order = $this->place('KDI', [$water], [['method' => 'cash', 'amount' => 20000]]);

        // 12.000 + service 5% (600) + pajak 10% dari 12.600 (1.260)
        $this->assertSame(12000, $order->subtotal);
        $this->assertSame(600, $order->service_charge_amount);
        $this->assertSame(1260, $order->tax_amount);
        $this->assertSame(13860, $order->total);
        $this->assertSame(6140, $order->change_amount);
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
    }

    public function test_tax_inclusive_prices_are_not_charged_twice(): void
    {
        $this->outlet('KLK')->update(['tax_enabled' => true, 'tax_rate' => 10, 'prices_include_tax' => true]);
        $water = ['menu_item_id' => $this->menu('NKP-003')->id, 'quantity' => 11];

        $order = $this->place('KLK', [$water], [['method' => 'qris', 'amount' => 66000]]);

        $this->assertSame(66000, $order->total);
        $this->assertSame(6000, $order->tax_amount);
        $this->assertTrue($order->prices_include_tax);
    }

    public function test_price_comes_from_outlet_variant_and_modifiers_not_from_client(): void
    {
        $line = $this->kopiSusuLarge(2) + ['unit_price' => 1];

        $order = $this->place('BAU', [$line], [['method' => 'cash', 'amount' => 100000]], cashier: $this->user('pabalu.supervisor@gmail.com'));

        // Harga khusus Baubau 20.000 + Large 6.000 + Extra Shot 5.000 = 31.000 per gelas
        $item = $order->items()->with('modifiers')->sole();
        $this->assertSame(31000, $item->unit_price);
        $this->assertSame(62000, $item->line_total);
        $this->assertSame('Large', $item->variant_name);
        $this->assertSame(['Dingin', 'Normal', 'Extra Shot'], $item->modifiers->pluck('name')->all());
        $this->assertSame(68200, $order->total); // + pajak 10%, tanpa service charge
    }

    public function test_order_keeps_its_prices_after_menu_changes(): void
    {
        $order = $this->place('KDI', [['menu_item_id' => $this->menu('CML-002')->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 50000]]);

        $this->menu('CML-002')->update(['name' => 'Kentang Goreng Jumbo', 'price' => 99000]);

        $item = $order->items()->sole();
        $this->assertSame('Kentang Goreng', $item->name);
        $this->assertSame(15000, $item->unit_price);
    }

    public function test_required_variant_and_modifier_rules_are_enforced(): void
    {
        $kopi = $this->kopiSusuLarge();

        $this->assertRejected('items.0', fn () => $this->place('KDI', [['variant_id' => null] + $kopi], [['method' => 'cash', 'amount' => 100000]]));

        // Level Gula wajib pilih tepat satu.
        $withoutSugar = ['modifier_ids' => [$this->modifier('Dingin')]] + $kopi;
        $this->assertRejected('items.0', fn () => $this->place('KDI', [$withoutSugar], [['method' => 'cash', 'amount' => 100000]]));

        $twoSugars = ['modifier_ids' => [$this->modifier('Dingin'), $this->modifier('Normal'), $this->modifier('No Sugar')]] + $kopi;
        $this->assertRejected('items.0', fn () => $this->place('KDI', [$twoSugars], [['method' => 'cash', 'amount' => 100000]]));

        // Modifier dari grup yang tidak terpasang di menu ini.
        $foreign = ['modifier_ids' => [$this->modifier('Dingin'), $this->modifier('Normal'), $this->modifier('Keju')]] + $kopi;
        $this->assertRejected('items.0', fn () => $this->place('KDI', [$foreign], [['method' => 'cash', 'amount' => 100000]]));
    }

    public function test_items_unavailable_at_outlet_or_from_other_business_are_rejected(): void
    {
        // Nasi goreng tidak dijual di Kolaka.
        $nasi = ['menu_item_id' => $this->menu('MKN-001')->id, 'modifier_ids' => [$this->modifier('Sedang')], 'quantity' => 1];
        $this->assertRejected('items.0', fn () => $this->place('KLK', [$nasi], [['method' => 'cash', 'amount' => 100000]], cashier: $this->user('pabalu.owner@gmail.com')));

        $other = MenuItem::factory()->create();
        $this->assertRejected('items.0', fn () => $this->place('KDI', [['menu_item_id' => $other->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 100000]]));
    }

    public function test_split_payment_allocates_non_cash_first_and_returns_change_from_cash(): void
    {
        $water = ['menu_item_id' => $this->menu('NKP-003')->id, 'quantity' => 2]; // total 13.860

        $order = $this->place('KDI', [$water], [
            ['method' => 'cash', 'amount' => 10000],
            ['method' => 'qris', 'amount' => 5000, 'reference' => 'QR-123'],
        ]);

        $payments = $order->payments()->get()->keyBy(fn ($p) => $p->method->value);
        $this->assertSame(8860, $payments['cash']->amount);
        $this->assertSame(10000, $payments['cash']->tendered_amount);
        $this->assertSame(1140, $payments['cash']->change_amount);
        $this->assertSame(5000, $payments['qris']->amount);
        $this->assertSame('QR-123', $payments['qris']->reference);
        $this->assertSame(13860, $order->paid_amount);
        $this->assertSame(1140, $order->change_amount);
    }

    public function test_invalid_payments_are_rejected(): void
    {
        $water = ['menu_item_id' => $this->menu('NKP-003')->id, 'quantity' => 2]; // total 13.860

        $this->assertRejected('payments', fn () => $this->place('KDI', [$water], [['method' => 'cash', 'amount' => 13000]]));
        $this->assertRejected('payments', fn () => $this->place('KDI', [$water], [['method' => 'qris', 'amount' => 20000]]));

        // Kartu kredit tidak aktif di Baubau.
        $this->assertRejected('payments.0.method', fn () => $this->place('BAU', [$water], [['method' => PaymentMethod::CreditCard->value, 'amount' => 20000]], cashier: $this->user('pabalu.supervisor@gmail.com')));
    }

    public function test_service_type_and_table_must_match_outlet_settings(): void
    {
        $water = ['menu_item_id' => $this->menu('NKP-003')->id, 'quantity' => 1];
        $owner = $this->user('pabalu.owner@gmail.com');

        // Kolaka tidak melayani dine-in; Baubau tidak memakai meja.
        $this->assertRejected('service_type', fn () => $this->place('KLK', [$water], [['method' => 'cash', 'amount' => 10000]], ['service_type' => 'dine_in'], $owner));
        $table = $this->outlet('KDI')->diningTables()->where('name', 'A4')->sole();
        $this->assertRejected('dining_table_id', fn () => $this->place('BAU', [$water], [['method' => 'cash', 'amount' => 10000]], ['service_type' => 'dine_in', 'dining_table_id' => $table->id], $owner));

        $order = $this->place('KDI', [$water], [['method' => 'cash', 'amount' => 10000]], ['service_type' => 'dine_in', 'dining_table_id' => $table->id]);
        $this->assertSame('A4', $order->table_number);
        $this->assertTrue($order->diningTable->is($table));
    }

    public function test_only_supervisor_and_above_can_give_discounts(): void
    {
        $water = ['menu_item_id' => $this->menu('NKP-003')->id, 'quantity' => 2];
        $discount = ['discount_type' => 'percent', 'discount_value' => 50, 'discount_reason' => 'Promo pembukaan'];

        $this->assertRejected('discount_value', fn () => $this->place('KDI', [$water], [['method' => 'cash', 'amount' => 20000]], $discount));

        $order = $this->place('KDI', [$water], [['method' => 'cash', 'amount' => 20000]], $discount, $this->user('pabalu.supervisor@gmail.com'));

        // 12.000 − 50% = 6.000 → service 300 → pajak 630
        $this->assertSame(6000, $order->discount_amount);
        $this->assertSame(6930, $order->total);
        $this->assertSame('Promo pembukaan', $order->discount_reason);
    }

    public function test_order_numbers_run_per_outlet_per_day(): void
    {
        $this->travelTo(now('Asia/Makassar')->setTime(9, 0));
        $water = ['menu_item_id' => $this->menu('NKP-003')->id, 'quantity' => 1];
        $pay = [['method' => 'cash', 'amount' => 10000]];
        $prefix = 'KDI-'.now('Asia/Makassar')->format('ymd').'-';

        $this->assertSame($prefix.'0001', $this->place('KDI', [$water], $pay)->number);
        $this->assertSame($prefix.'0002', $this->place('KDI', [$water], $pay)->number);
        $this->assertStringEndsWith('-0001', $this->place('BAU', [$water], $pay, cashier: $this->user('pabalu.supervisor@gmail.com'))->number);

        $this->travel(1)->days();
        $this->assertStringEndsWith('-0001', $this->place('KDI', [$water], $pay)->number);
    }

    public function test_resending_the_same_client_reference_returns_the_existing_order(): void
    {
        $water = ['menu_item_id' => $this->menu('NKP-003')->id, 'quantity' => 1];
        $pay = [['method' => 'cash', 'amount' => 10000]];

        $first = $this->place('KDI', [$water], $pay, ['client_reference' => 'dev-1-0001']);
        $again = $this->place('KDI', [$water], $pay, ['client_reference' => 'dev-1-0001']);

        $this->assertTrue($first->is($again));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, $first->payments()->count());
    }

    public function test_order_records_cashier_and_status_history(): void
    {
        $order = $this->place('KDI', [['menu_item_id' => $this->menu('NKP-003')->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 10000]]);

        $this->assertTrue($order->cashier->is($this->kasir));
        $history = $order->statusHistories()->sole();
        $this->assertSame(OrderStatus::Completed, $history->to_status);
        $this->assertTrue($history->user->is($this->kasir));
        $this->assertSame(32, strlen($order->receipt_token));
    }
}
