<?php

namespace Tests\Feature\Api;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $kendari;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->kendari = Outlet::where('code', 'KDI')->sole();
    }

    private function actingAsUser(string $email): User
    {
        $user = User::where('email', $email)->sole();
        Sanctum::actingAs($user, ['pos']);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'client_reference' => 'tablet-1-000001',
            'service_type' => 'takeaway',
            'items' => [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ];
    }

    public function test_menu_endpoint_returns_outlet_prices_and_availability(): void
    {
        $this->actingAsUser('pabalu.supervisor@gmail.com');
        $baubau = Outlet::where('code', 'BAU')->sole();
        $kolaka = Outlet::where('code', 'KLK')->sole();

        $items = collect($this->getJson("/api/v1/outlets/{$baubau->id}/menu")
            ->assertOk()
            ->assertJsonCount(4, 'data.categories')
            ->json('data.items'))->keyBy('sku');

        $this->assertSame(20000, $items['KOP-001']['price']);
        $this->assertSame(26000, collect($items['KOP-001']['variants'])->firstWhere('name', 'Large')['price']);
        $this->assertSame(1, collect($items['KOP-001']['modifier_groups'])->firstWhere('name', 'Level Gula')['max_select']);
        $this->assertTrue($items['MKN-001']['is_available']);

        // Supervisor tidak ditugaskan ke Kolaka.
        $this->getJson("/api/v1/outlets/{$kolaka->id}/menu")->assertForbidden();
    }

    public function test_kasir_places_order_and_resend_is_idempotent(): void
    {
        $this->actingAsUser('pabalu.kasir@gmail.com');

        $this->postJson("/api/v1/outlets/{$this->kendari->id}/orders", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.total', 13860)
            ->assertJsonPath('data.change_amount', 6140)
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.cashier.name', 'Dedi Kasir');

        $this->postJson("/api/v1/outlets/{$this->kendari->id}/orders", $this->payload())
            ->assertOk()
            ->assertJsonPath('data.total', 13860);

        $this->assertSame(1, Order::count());
    }

    public function test_order_validation_errors_are_returned_as_json(): void
    {
        $this->actingAsUser('pabalu.kasir@gmail.com');

        $this->postJson("/api/v1/outlets/{$this->kendari->id}/orders", $this->payload([
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('payments');

        $this->postJson("/api/v1/outlets/{$this->kendari->id}/orders", $this->payload(['items' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_kasir_cannot_sell_or_read_orders_at_unassigned_outlet(): void
    {
        $this->actingAsUser('pabalu.supervisor@gmail.com');
        $baubau = Outlet::where('code', 'BAU')->sole();
        $orderId = $this->postJson("/api/v1/outlets/{$baubau->id}/orders", $this->payload())->assertCreated()->json('data.id');

        $this->actingAsUser('pabalu.kasir@gmail.com');

        $this->postJson("/api/v1/outlets/{$baubau->id}/orders", $this->payload(['client_reference' => 'x-2']))->assertForbidden();
        $this->getJson("/api/v1/outlets/{$baubau->id}/orders")->assertForbidden();
        $this->getJson("/api/v1/orders/{$orderId}")->assertForbidden();
        $this->getJson("/api/v1/orders/{$orderId}/receipt")->assertForbidden();
    }

    public function test_order_history_lists_today_for_the_outlet(): void
    {
        $this->actingAsUser('pabalu.kasir@gmail.com');
        $this->postJson("/api/v1/outlets/{$this->kendari->id}/orders", $this->payload())->assertCreated();

        $this->getJson("/api/v1/outlets/{$this->kendari->id}/orders")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/outlets/{$this->kendari->id}/orders?date=2020-01-01")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_receipt_endpoint_returns_escpos_with_qr_to_web_receipt(): void
    {
        $this->actingAsUser('pabalu.kasir@gmail.com');
        $order = Order::findOrFail($this->postJson("/api/v1/outlets/{$this->kendari->id}/orders", $this->payload())->json('data.id'));

        $data = $this->getJson("/api/v1/orders/{$order->id}/receipt")
            ->assertOk()
            ->assertJsonPath('data.paper_width', 58)
            ->assertJsonPath('data.columns', 32)
            ->assertJsonPath('data.receipt_url', route('receipts.show', $order->receipt_token))
            ->json('data');

        $bytes = base64_decode($data['escpos_base64']);
        $this->assertStringStartsWith("\x1B@", $bytes);
        $this->assertStringContainsString("\x1D(k".chr(strlen($data['receipt_url']) + 3)."\x00\x31\x50\x30".$data['receipt_url'], $bytes);
        $this->assertStringContainsString("\x1DVB\x00", $bytes);

        $this->assertStringContainsString($order->number, $data['text']);
        $this->assertStringContainsString('Air Mineral', $data['text']);
        foreach (explode("\n", $data['text']) as $line) {
            $this->assertLessThanOrEqual(32, strlen($line));
        }

        $this->getJson("/api/v1/orders/{$order->id}/receipt?paper_width=80")->assertJsonPath('data.columns', 48);
    }

    public function test_web_receipt_is_public_by_token_only(): void
    {
        $this->actingAsUser('pabalu.kasir@gmail.com');
        $order = Order::findOrFail($this->postJson("/api/v1/outlets/{$this->kendari->id}/orders", $this->payload())->json('data.id'));
        $this->app['auth']->forgetGuards();

        $this->get(route('receipts.show', $order->receipt_token))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee('Air Mineral')
            ->assertSee('Rp 13.860')
            ->assertSee('<svg', false);

        $this->get('/r/'.str_repeat('a', 32))->assertNotFound();
        $this->get('/r/'.$order->id)->assertNotFound();
    }
}
