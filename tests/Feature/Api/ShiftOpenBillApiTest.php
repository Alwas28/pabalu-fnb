<?php

namespace Tests\Feature\Api;

use App\Models\CashShift;
use App\Models\MenuItem;
use App\Models\Outlet;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftOpenBillApiTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $kendari;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->kendari = Outlet::where('code', 'KDI')->sole();
        Sanctum::actingAs(User::where('email', 'pabalu.kasir@gmail.com')->sole(), ['pos']);
    }

    private function water(int $quantity = 2): array
    {
        return [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => $quantity]];
    }

    public function test_full_shift_and_open_bill_flow(): void
    {
        $this->getJson("/api/v1/outlets/{$this->kendari->id}/shift")->assertOk()->assertJsonPath('data', null);

        $this->postJson("/api/v1/outlets/{$this->kendari->id}/orders", [
            'service_type' => 'takeaway', 'items' => $this->water(), 'payments' => [['method' => 'cash', 'amount' => 20000]],
        ])->assertUnprocessable()->assertJsonValidationErrors('shift');

        $shiftId = $this->postJson("/api/v1/outlets/{$this->kendari->id}/shifts", ['opening_cash' => 100000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->json('data.id');

        $table = $this->kendari->diningTables()->where('name', 'B2')->sole();
        $orderId = $this->postJson("/api/v1/outlets/{$this->kendari->id}/orders", [
            'service_type' => 'dine_in', 'pay_later' => true, 'dining_table_id' => $table->id, 'items' => $this->water(),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.table_number', 'B2')
            ->json('data.id');

        $this->getJson("/api/v1/outlets/{$this->kendari->id}/orders?status=open")->assertOk()->assertJsonCount(1, 'data');

        $tables = collect($this->getJson("/api/v1/outlets/{$this->kendari->id}/tables")
            ->assertOk()->assertJsonPath('data.status_tracking', true)->json('data.tables'))->keyBy('name');
        $this->assertTrue($tables['B2']['is_occupied']);
        $this->assertSame($orderId, $tables['B2']['open_orders'][0]['id']);

        $this->postJson("/api/v1/orders/{$orderId}/items", ['items' => $this->water(1)])
            ->assertOk()->assertJsonPath('data.total', 20790);

        $this->postJson("/api/v1/orders/{$orderId}/cancel", ['reason' => 'x'])->assertForbidden(); // kasir

        $this->postJson("/api/v1/orders/{$orderId}/settle", ['payments' => [['method' => 'cash', 'amount' => 50000]]])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.change_amount', 29210);

        $this->postJson("/api/v1/shifts/{$shiftId}/cash-movements", ['type' => 'out', 'amount' => 10000, 'reason' => 'Beli gas'])
            ->assertCreated()
            ->assertJsonPath('data.summary.expected_cash', 100000 + 20790 - 10000);

        $this->postJson("/api/v1/shifts/{$shiftId}/close", ['actual_cash' => 110790])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.cash_difference', 0);

        $this->assertFalse(CashShift::findOrFail($shiftId)->isOpen());
    }

    public function test_cashier_cannot_touch_another_cashiers_shift(): void
    {
        $other = User::where('email', 'pabalu.supervisor@gmail.com')->sole();
        $shift = CashShift::create([
            'business_id' => $this->kendari->business_id, 'outlet_id' => $this->kendari->id, 'user_id' => $other->id,
            'status' => 'open', 'opening_cash' => 0, 'opened_at' => now(),
        ]);

        $this->getJson("/api/v1/shifts/{$shift->id}")->assertForbidden();
        $this->postJson("/api/v1/shifts/{$shift->id}/close", ['actual_cash' => 0])->assertForbidden();
    }
}
