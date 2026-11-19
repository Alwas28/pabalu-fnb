<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\QuickPurchase;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Purchasing\QuickPurchaseService;
use App\Support\Reports\FinanceMetrics;
use App\Support\Reports\Period;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Data demo: Minyak Goreng (ml, beli per liter = 1000 ml), saldo awal 6000 ml @ Rp 19/ml. */
class QuickPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Outlet $kendari;

    private Ingredient $oil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->manager = User::where('email', 'pabalu.manager@gmail.com')->sole();
        $this->kendari = Outlet::where('code', 'KDI')->sole();
        $this->oil = Ingredient::where('name', 'Minyak Goreng')->sole();
    }

    private function stock(): float
    {
        return app(StockLedger::class)->quantity($this->kendari, $this->oil);
    }

    private function cashFlow(): array
    {
        $business = $this->kendari->business;

        return (new FinanceMetrics($business->id, [$this->kendari->id], Period::make(null, null, $business->timezone)))->cashFlow();
    }

    /** @param  array<string, mixed>  $line */
    private function record(array $line, array $input = []): QuickPurchase
    {
        return app(QuickPurchaseService::class)->record($this->manager, $this->kendari, $input + [
            'purchased_at' => now($this->kendari->business->timezone)->toDateString(),
            'method' => 'cash',
            'items' => [$line + ['ingredient_id' => $this->oil->id]],
        ]);
    }

    public function test_quick_purchase_adds_stock_with_cost_and_counts_as_cash_out_only(): void
    {
        $this->record(['quantity' => '800', 'unit' => 'base', 'total_price' => '15000'], ['store_name' => 'Warung Bu Ani']);

        $this->assertEqualsWithDelta(6800, $this->stock(), 0.001);
        // (6000 × 19 + 800 × 18,75) / 6800
        $this->assertEqualsWithDelta(18.9706, app(StockLedger::class)->averageCost($this->kendari, $this->oil), 0.0001);
        $this->assertSame(StockMovementType::QuickPurchase, $this->oil->movements()->latest('id')->first()->type);

        $cash = $this->cashFlow();
        $this->assertSame(15000, $cash['quick_purchases']);
        $this->assertSame(0, $cash['expenses']); // bukan pengeluaran operasional (sudah lewat HPP)

        $this->record(['quantity' => '2', 'unit' => 'purchase', 'total_price' => '40000']); // 2 liter
        $this->assertEqualsWithDelta(8800, $this->stock(), 0.001);
    }

    public function test_cancelling_returns_stock_and_removes_cash_out(): void
    {
        $purchase = $this->record(['quantity' => '800', 'unit' => 'base', 'total_price' => '15000']);
        $service = app(QuickPurchaseService::class);

        $service->cancel($this->manager, $purchase);

        $this->assertEqualsWithDelta(6000, $this->stock(), 0.001);
        $this->assertSame(0, $this->cashFlow()['quick_purchases']);
        $this->expectException(ValidationException::class);
        $service->cancel($this->manager, $purchase->fresh());
    }

    public function test_rejects_future_date_and_missing_purchase_unit(): void
    {
        $tomorrow = now($this->kendari->business->timezone)->addDay()->toDateString();
        $lemon = Ingredient::where('name', 'Lemon')->sole();
        $lemon->update(['purchase_unit' => null, 'purchase_unit_size' => null]);

        foreach ([
            'purchased_at' => fn () => $this->record(['quantity' => '1', 'total_price' => '1000'], ['purchased_at' => $tomorrow]),
            'items.0.unit' => fn () => $this->record(['ingredient_id' => $lemon->id, 'quantity' => '1', 'unit' => 'purchase', 'total_price' => '1000']),
        ] as $field => $callback) {
            try {
                $callback();
                $this->fail("Seharusnya ditolak pada [{$field}].");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->assertSame(0, QuickPurchase::count());
    }

    public function test_page_records_purchase_and_is_hidden_from_cashier(): void
    {
        $this->actingAs(User::where('email', 'pabalu.kasir@gmail.com')->sole())->get(route('purchasing.quick'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('purchasing.quick'))->assertOk()->assertSee('Riwayat Belanja');

        Volt::test('pages.purchasing.quick')
            ->dispatch('quick-purchase-open')
            ->set('items.0.ingredient_id', (string) $this->oil->id)
            ->set('items.0.quantity', '800')
            ->set('items.0.total_price', '15000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(6800, $this->stock(), 0.001);
    }
}
