<?php

namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Purchasing\PurchaseService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Data demo: Susu UHT di Kendari 12.000 ml @ Rp18/ml; satuan beli "liter" = 1.000 ml.
 */
class PurchasingTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Outlet $kendari;

    private PurchaseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->manager = User::where('email', 'pabalu.manager@gmail.com')->sole();
        $this->kendari = Outlet::where('code', 'KDI')->sole();
        $this->service = app(PurchaseService::class);
    }

    private function milk(): Ingredient
    {
        return Ingredient::where('name', 'Susu UHT')->sole();
    }

    /** 20 liter @ Rp22.000 = Rp440.000 */
    private function orderedPo(): PurchaseOrder
    {
        $po = $this->service->saveDraft($this->manager, $this->kendari, [
            'supplier_id' => Supplier::where('name', 'UD Sumber Susu')->value('id'),
            'order_date' => now()->toDateString(),
            'items' => [['ingredient_id' => $this->milk()->id, 'quantity' => 20, 'unit' => 'purchase', 'unit_price' => 22000]],
        ]);

        return $this->service->markOrdered($po);
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

    public function test_partial_receipts_add_stock_and_update_average_cost(): void
    {
        $po = $this->orderedPo();
        $item = $po->items()->sole();
        $this->assertSame(440000, $po->total);
        $this->assertStringStartsWith('PO-KDI-', $po->number);

        $this->service->receive($this->manager, $po, [$item->id => '8']);
        $po->refresh();
        $this->assertSame(PurchaseStatus::Partial, $po->status);
        $this->assertSame(176000, $po->received_total);

        $ledger = app(StockLedger::class);
        $this->assertEqualsWithDelta(20000, $ledger->quantity($this->kendari, $this->milk()), 0.001);
        // (12.000 × 18 + 8.000 × 22) / 20.000 = 19,6
        $this->assertEqualsWithDelta(19.6, $ledger->averageCost($this->kendari, $this->milk()), 0.0001);

        $this->assertInvalid("receive.{$item->id}", fn () => $this->service->receive($this->manager, $po, [$item->id => '13']));

        $this->service->receive($this->manager, $po, [$item->id => '12']);
        $this->assertSame(PurchaseStatus::Received, $po->fresh()->status);
        $this->assertInvalid('receive', fn () => $this->service->receive($this->manager, $po->fresh(), [$item->id => '1']));
    }

    public function test_bill_follows_received_goods_minus_returns(): void
    {
        $po = $this->orderedPo();
        $item = $po->items()->sole();
        $this->service->receive($this->manager, $po, [$item->id => '10']); // 220.000

        $this->service->returnGoods($this->manager, $po->fresh(), [$item->id => '2'], 'Kemasan bocor'); // -44.000
        $po->refresh();
        $this->assertSame(176000, $po->payable());
        $this->assertEqualsWithDelta(12000 + 8000, app(StockLedger::class)->quantity($this->kendari, $this->milk()), 0.001);

        $this->assertInvalid('amount', fn () => $this->service->pay($this->manager, $po, ['amount' => 200000, 'method' => 'bank_transfer', 'paid_at' => now()->toDateString()]));

        $this->service->pay($this->manager, $po, ['amount' => 100000, 'method' => 'bank_transfer', 'paid_at' => now()->toDateString()]);
        $this->assertSame('partial', $po->fresh()->billStatus());
        $this->service->pay($this->manager, $po->fresh(), ['amount' => 76000, 'method' => 'cash', 'paid_at' => now()->toDateString()]);
        $this->assertSame('paid', $po->fresh()->billStatus());

        $this->assertInvalid("return.{$item->id}", fn () => $this->service->returnGoods($this->manager, $po->fresh(), [$item->id => '9'], 'x'));
    }

    public function test_draft_rules_and_cancellation(): void
    {
        $this->assertInvalid('items', fn () => $this->service->saveDraft($this->manager, $this->kendari, [
            'supplier_id' => Supplier::value('id'), 'order_date' => now()->toDateString(), 'items' => [],
        ]));

        $po = $this->orderedPo();
        $this->assertInvalid('order', fn () => $this->service->saveDraft($this->manager, $this->kendari, [], $po));

        $this->service->receive($this->manager, $po, [$po->items()->value('id') => '1']);
        $this->assertInvalid('order', fn () => $this->service->cancel($po->fresh()));

        $draft = $this->service->saveDraft($this->manager, $this->kendari, [
            'supplier_id' => Supplier::value('id'), 'order_date' => now()->toDateString(),
            'items' => [['ingredient_id' => $this->milk()->id, 'quantity' => 500, 'unit' => 'base', 'unit_price' => 20]],
        ]);
        $this->assertSame('ml', $draft->items()->sole()->unit_label);
        $this->assertSame(PurchaseStatus::Cancelled, $this->service->cancel($draft)->status);
    }

    public function test_purchasing_pages_and_form(): void
    {
        $this->actingAs($this->manager)->get(route('purchasing.index'))->assertOk();
        $this->actingAs($this->manager)->get(route('purchasing.suppliers'))->assertOk()->assertSee('UD Sumber Susu');
        $this->actingAs(User::where('email', 'pabalu.kasir@gmail.com')->sole())->get(route('purchasing.index'))->assertForbidden();

        $this->actingAs($this->manager);
        Volt::test('pages.purchasing.form')
            ->set('supplierId', (string) Supplier::where('name', 'UD Sumber Susu')->value('id'))
            ->set('items.0.ingredient_id', (string) $this->milk()->id)
            ->set('items.0.quantity', '5')
            ->set('items.0.unit', 'purchase')
            ->set('items.0.unit_price', '21000')
            ->call('save', true)
            ->assertHasNoErrors();

        $po = PurchaseOrder::sole();
        $this->assertSame(PurchaseStatus::Ordered, $po->status);

        Volt::test('pages.purchasing.show', ['purchaseOrder' => $po])
            ->call('openReceive')
            ->call('saveReceive')
            ->assertHasNoErrors()
            ->call('openPay')
            ->call('savePay')
            ->assertHasNoErrors();

        $this->assertSame('paid', $po->fresh()->billStatus());

        // PO outlet lain tidak bisa dibuka manager Kendari.
        $baubau = Outlet::where('code', 'BAU')->sole();
        $foreign = $this->service->saveDraft(User::where('email', 'pabalu.owner@gmail.com')->sole(), $baubau, [
            'supplier_id' => Supplier::value('id'), 'order_date' => now()->toDateString(),
            'items' => [['ingredient_id' => $this->milk()->id, 'quantity' => 1, 'unit' => 'purchase', 'unit_price' => 1]],
        ]);
        $this->get(route('purchasing.show', $foreign))->assertNotFound();
    }
}
