<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\CashShift;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Pos\OrderTotals;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\ShiftManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Kendari: pajak 10% + service 5%, stok berkurang dari resep (air mineral = 1 botol per porsi).
 */
class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private Outlet $kendari;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->kasir = User::where('email', 'pabalu.kasir@gmail.com')->sole();
        $this->kendari = Outlet::where('code', 'KDI')->sole();
        $this->kendari->update(['offline_enabled' => true]);
        Sanctum::actingAs($this->kasir, ['pos']);
    }

    private function registerDevice(): PosDevice
    {
        $id = $this->postJson("/api/v1/outlets/{$this->kendari->id}/pos-devices", ['name' => 'Tablet Kasir', 'device_uid' => 'android-1'])
            ->assertCreated()
            ->json('data.id');

        return PosDevice::findOrFail($id);
    }

    private function number(PosDevice $device, int $sequence): string
    {
        return 'KDI-'.now('Asia/Makassar')->format('ymd').'-'.$device->code.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Pesanan seperti yang dikirim aplikasi: harga & total sesuai yang tercetak di struk.
     *
     * @param  list<array{0: string, 1: int, 2?: int}>  $lines  [sku, qty, harga per porsi (opsional)]
     */
    private function orderOperation(PosDevice $device, int $sequence, array $lines, array $extra = []): array
    {
        $items = array_map(function (array $line) {
            $menu = MenuItem::where('sku', $line[0])->sole();

            return ['menu_item_id' => $menu->id, 'name' => $menu->name, 'unit_price' => $line[2] ?? $menu->priceAt($this->kendari), 'quantity' => $line[1]];
        }, $lines);

        $subtotal = array_sum(array_map(fn ($i) => $i['unit_price'] * $i['quantity'], $items));
        $totals = OrderTotals::calculate($this->kendari, $subtotal);

        return $extra + [
            'type' => 'order',
            'client_reference' => (string) Str::uuid(),
            'shift_reference' => 'shift-1',
            'number' => $this->number($device, $sequence),
            'placed_at' => now()->subHour()->toIso8601String(),
            'service_type' => 'takeaway',
            'items' => $items,
            'totals' => [
                'subtotal' => $totals->subtotal, 'discount_amount' => 0,
                'service_charge_rate' => $totals->serviceChargeRate, 'service_charge_amount' => $totals->serviceCharge,
                'tax_rate' => $totals->taxRate, 'prices_include_tax' => $totals->pricesIncludeTax,
                'tax_amount' => $totals->tax, 'total' => $totals->total,
            ],
            'payments' => [['method' => 'cash', 'amount' => $totals->total + 5000]],
        ];
    }

    private function sync(PosDevice $device, array $operations): array
    {
        return $this->postJson("/api/v1/pos-devices/{$device->id}/sync", ['operations' => $operations])->assertOk()->json('data');
    }

    private function water(): float
    {
        return app(StockLedger::class)->quantity($this->kendari, Ingredient::where('name', 'Air Mineral 600ml')->sole());
    }

    public function test_device_registration_requires_offline_mode_and_is_idempotent(): void
    {
        $this->kendari->update(['offline_enabled' => false]);
        $this->postJson("/api/v1/outlets/{$this->kendari->id}/pos-devices", ['name' => 'Tablet', 'device_uid' => 'android-1'])->assertForbidden();

        $this->kendari->update(['offline_enabled' => true]);
        $device = $this->registerDevice();
        $this->assertSame('K1', $device->code);

        $this->postJson("/api/v1/outlets/{$this->kendari->id}/pos-devices", ['name' => 'Tablet', 'device_uid' => 'android-1'])
            ->assertOk()->assertJsonPath('data.code', 'K1');
        $this->postJson("/api/v1/outlets/{$this->kendari->id}/pos-devices", ['name' => 'Tablet 2', 'device_uid' => 'android-2'])
            ->assertCreated()->assertJsonPath('data.code', 'K2');

        $this->getJson("/api/v1/pos-devices/{$device->id}/bundle")
            ->assertOk()
            ->assertJsonPath('data.offline.number_format', 'KDI-YYMMDD-K1-0001')
            ->assertJsonPath('data.outlet.config.tax.rate', 10)
            ->assertJsonPath('data.current_shift', null);

        $device->update(['revoked_at' => now()]);
        $this->getJson("/api/v1/pos-devices/{$device->id}/bundle")->assertForbidden();
        $this->postJson("/api/v1/pos-devices/{$device->id}/sync", ['operations' => [['type' => 'shift_open']]])->assertForbidden();
    }

    public function test_offline_shift_and_sales_sync_once(): void
    {
        $device = $this->registerDevice();
        $water = $this->water();
        $placedAt = now()->subHours(2)->startOfMinute();
        $order = $this->orderOperation($device, 1, [['NKP-003', 2]], ['placed_at' => $placedAt->toIso8601String()]);

        $operations = [
            ['type' => 'shift_open', 'client_reference' => 'shift-1', 'opening_cash' => 100000, 'opened_at' => now()->subHours(3)->toIso8601String()],
            $order,
            ['type' => 'cash_movement', 'client_reference' => 'mv-1', 'shift_reference' => 'shift-1', 'movement_type' => 'out', 'amount' => 20000, 'reason' => 'Beli es batu', 'created_at' => now()->subMinutes(30)->toIso8601String()],
            ['type' => 'shift_close', 'shift_reference' => 'shift-1', 'actual_cash' => 100000 + $order['totals']['total'] - 20000, 'closed_at' => now()->toIso8601String()],
        ];

        $results = $this->sync($device, $operations);
        $this->assertSame(['created', 'created', 'created', 'created'], array_column($results, 'status'));
        $this->assertSame([], $results[1]['issues']);

        $saved = Order::sole();
        $this->assertSame($this->number($device, 1), $saved->number);
        $this->assertTrue($saved->placed_offline);
        $this->assertSame(OrderStatus::Completed, $saved->status);
        $this->assertSame(PaymentStatus::Paid, $saved->payment_status);
        $this->assertTrue($saved->placed_at->equalTo($placedAt));
        $this->assertTrue($saved->payments()->sole()->paid_at->equalTo($placedAt));
        $this->assertSame(5000, $saved->change_amount);
        $this->assertEqualsWithDelta($water - 2, $this->water(), 0.001);

        $shift = CashShift::where('client_reference', 'shift-1')->sole();
        $this->assertSame(ShiftStatus::Closed, $shift->status);
        $this->assertSame(0, $shift->cash_difference);
        $this->assertNotNull($device->fresh()->last_synced_at);

        // Kiriman ulang (mis. koneksi putus sebelum jawaban diterima) tidak menggandakan data.
        $again = $this->sync($device, $operations);
        $this->assertSame(['duplicate', 'duplicate', 'duplicate', 'duplicate'], array_column($again, 'status'));
        $this->assertSame(1, Order::count());
        $this->assertEqualsWithDelta($water - 2, $this->water(), 0.001);
    }

    public function test_printed_prices_are_kept_and_differences_are_flagged(): void
    {
        $device = $this->registerDevice();
        $shift = app(ShiftManager::class)->open($this->kasir, $this->kendari, ['opening_cash' => 0]);

        $oldPrice = $this->orderOperation($device, 1, [['NKP-003', 1, 5000]], ['shift_id' => $shift->id, 'shift_reference' => null]);
        $deletedMenu = $this->orderOperation($device, 2, [['NKP-003', 1]], ['shift_id' => $shift->id, 'shift_reference' => null]);
        $deletedMenu['items'][0] = ['menu_item_id' => 999999, 'name' => 'Menu Lama'] + $deletedMenu['items'][0];
        $broken = $this->orderOperation($device, 3, [['NKP-003', 1]], ['shift_id' => $shift->id, 'shift_reference' => null]);
        $broken['totals']['total'] += 1000;
        $tooLate = $this->orderOperation($device, 4, [['NKP-003', 1]], ['shift_id' => $shift->id, 'shift_reference' => null, 'placed_at' => now()->subHours(30)->toIso8601String()]);
        $reusedNumber = $this->orderOperation($device, 1, [['NKP-003', 1]], ['shift_id' => $shift->id, 'shift_reference' => null]);

        $results = $this->sync($device, [$oldPrice, $deletedMenu, $broken, $tooLate, $reusedNumber]);

        $this->assertSame(['created', 'created', 'rejected', 'created', 'created'], array_column($results, 'status'));
        $this->assertStringContainsString('di perangkat Rp 5.000, di server Rp 6.000', $results[0]['issues'][0]);
        $this->assertSame(5000, Order::find($results[0]['id'])->subtotal);
        $this->assertStringContainsString('Menu Lama', $results[1]['issues'][0]);
        $this->assertNull(Order::find($results[1]['id'])->items()->sole()->menu_item_id);
        $this->assertArrayHasKey('totals.total', $results[2]['errors']);
        $this->assertStringContainsString('lebih dari 24 jam', $results[3]['issues'][0]);
        $this->assertSame($this->number($device, 1).'A', $results[4]['number']);
        $this->assertNotEmpty(Order::find($results[0]['id'])->sync_issues);
    }

    public function test_offline_numbers_do_not_affect_online_numbering(): void
    {
        $device = $this->registerDevice();
        $shift = app(ShiftManager::class)->open($this->kasir, $this->kendari, ['opening_cash' => 0]);
        $this->sync($device, [$this->orderOperation($device, 7, [['NKP-003', 1]], ['shift_id' => $shift->id, 'shift_reference' => null])]);

        $online = app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'takeaway',
            'items' => [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100000]],
        ]);

        $this->assertStringEndsWith(now('Asia/Makassar')->format('ymd').'-0001', $online->number);
    }

    public function test_owner_sees_flagged_offline_orders_and_can_revoke_devices(): void
    {
        $device = $this->registerDevice();
        $shift = app(ShiftManager::class)->open($this->kasir, $this->kendari, ['opening_cash' => 0]);
        $result = $this->sync($device, [$this->orderOperation($device, 1, [['NKP-003', 1, 5000]], ['shift_id' => $shift->id, 'shift_reference' => null])]);
        $order = Order::findOrFail($result[0]['id']);

        $owner = User::where('email', 'pabalu.owner@gmail.com')->sole();
        $this->actingAs($owner);

        Volt::test('pages.orders.index')
            ->set('outletId', (string) $this->kendari->id)
            ->set('syncFilter', 'review')
            ->assertSee($order->number);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Transaksi offline dari perangkat K1')->assertSee('di server Rp 6.000');

        Volt::test('pages.settings.outlet-form', ['outlet' => $this->kendari])
            ->assertSee('Tablet Kasir')
            ->call('revokeDevice', $device->id);
        $this->assertTrue($device->fresh()->isRevoked());
    }
}
