<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use App\Support\Inventory\StockOperations;
use App\Support\Pos\CancelOrder;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\RefundService;
use App\Support\Pos\ShiftManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Data demo: Kendari mengurangi stok dari resep, Baubau tidak. Saldo awal sama di tiap outlet.
 * Kopi Susu Gula Aren: 18 g kopi + 150 ml susu + 25 ml gula aren; Large +80 ml susu +9 g kopi; Extra Shot +9 g kopi.
 */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private User $manager;

    private Outlet $kendari;

    private StockLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->kasir = User::where('email', 'pabalu.kasir@gmail.com')->sole();
        $this->manager = User::where('email', 'pabalu.manager@gmail.com')->sole();
        $this->kendari = Outlet::where('code', 'KDI')->sole();
        $this->ledger = app(StockLedger::class);
        app(ShiftManager::class)->open($this->kasir, $this->kendari, ['opening_cash' => 0]);
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::where('name', $name)->sole();
    }

    private function stock(string $name, ?Outlet $outlet = null): float
    {
        return $this->ledger->quantity($outlet ?? $this->kendari, $this->ingredient($name));
    }

    private function sellKopiLarge(int $quantity = 2): Order
    {
        $kopi = MenuItem::where('sku', 'KOP-001')->sole();
        $modifier = fn (string $name) => Modifier::where('name', $name)->value('id');

        return app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'takeaway',
            'items' => [[
                'menu_item_id' => $kopi->id,
                'variant_id' => $kopi->variants()->where('name', 'Large')->value('id'),
                'modifier_ids' => [$modifier('Dingin'), $modifier('Normal'), $modifier('Extra Shot')],
                'quantity' => $quantity,
            ]],
            'payments' => [['method' => 'cash', 'amount' => 200000]],
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

    public function test_sale_deducts_recipe_with_variant_and_modifier_extras(): void
    {
        $order = $this->sellKopiLarge(2);

        // (18 + 9 Large + 9 Extra Shot) × 2 = 72 g; susu (150 + 80) × 2 = 460 ml; gula aren 25 × 2 = 50 ml
        $this->assertEqualsWithDelta(3000 - 72, $this->stock('Biji Kopi Arabika'), 0.001);
        $this->assertEqualsWithDelta(12000 - 460, $this->stock('Susu UHT'), 0.001);
        $this->assertEqualsWithDelta(2500 - 50, $this->stock('Gula Aren Cair'), 0.001);

        // Biaya bahan: kopi 180/g, susu 18/ml, gula aren 45/ml → 72×180 + 460×18 + 50×45 = 23.490
        $this->assertSame(23490, $order->items()->sole()->cost_amount);
    }

    public function test_outlet_without_deduction_still_records_food_cost(): void
    {
        $baubau = Outlet::where('code', 'BAU')->sole();
        $supervisor = User::where('email', 'pabalu.supervisor@gmail.com')->sole();
        app(ShiftManager::class)->open($supervisor, $baubau, ['opening_cash' => 0]);

        $order = app(PlaceOrder::class)->handle($supervisor, $baubau, [
            'service_type' => 'takeaway',
            'items' => [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ]);

        $this->assertEqualsWithDelta(48, $this->stock('Air Mineral 600ml', $baubau), 0.001);
        $this->assertSame(6000, $order->items()->sole()->cost_amount); // 3 × (48.000 / 24)
    }

    public function test_void_and_cancel_return_used_ingredients(): void
    {
        $order = $this->sellKopiLarge(1);
        $owner = User::where('email', 'pabalu.owner@gmail.com')->sole();

        app(RefundService::class)->request($owner, $order, ['type' => 'void', 'reason' => 'Salah input']);
        $this->assertEqualsWithDelta(3000, $this->stock('Biji Kopi Arabika'), 0.001);

        $openBill = app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'dine_in', 'pay_later' => true,
            'dining_table_id' => $this->kendari->diningTables()->value('id'),
            'items' => [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => 2]],
        ]);
        $this->assertEqualsWithDelta(46, $this->stock('Air Mineral 600ml'), 0.001);

        app(CancelOrder::class)->handle(User::where('email', 'pabalu.supervisor@gmail.com')->sole(), $openBill, ['reason' => 'Batal']);
        $this->assertEqualsWithDelta(48, $this->stock('Air Mineral 600ml'), 0.001);
        $this->assertSame(1, StockMovement::where('type', StockMovementType::RecipeReturn)->where('reference_id', $openBill->id)->count());
    }

    public function test_incoming_stock_updates_weighted_average_cost(): void
    {
        $milk = $this->ingredient('Susu UHT'); // 12.000 ml @ 18

        app(StockOperations::class)->adjust($this->manager, $this->kendari, ['ingredient_id' => $milk->id, 'quantity' => 4000, 'unit_cost' => 22, 'reason' => 'Titipan']);

        // (12.000 × 18 + 4.000 × 22) / 16.000 = 19
        $this->assertEqualsWithDelta(19, $this->ledger->averageCost($this->kendari, $milk), 0.0001);
        $this->assertEqualsWithDelta(16000, $this->stock('Susu UHT'), 0.001);
    }

    public function test_waste_and_adjustment_require_reason(): void
    {
        $lemon = $this->ingredient('Lemon');
        $ops = app(StockOperations::class);

        $this->assertInvalid('reason', fn () => $ops->waste($this->manager, $this->kendari, ['ingredient_id' => $lemon->id, 'quantity' => 2, 'reason' => '']));
        $this->assertInvalid('quantity', fn () => $ops->waste($this->manager, $this->kendari, ['ingredient_id' => $lemon->id, 'quantity' => -2, 'reason' => 'x']));

        $ops->waste($this->manager, $this->kendari, ['ingredient_id' => $lemon->id, 'quantity' => 2, 'reason' => 'Busuk']);
        $ops->adjust($this->manager, $this->kendari, ['ingredient_id' => $lemon->id, 'quantity' => -3, 'reason' => 'Koreksi']);

        $this->assertEqualsWithDelta(25, $this->stock('Lemon'), 0.001);
        $movement = StockMovement::where('type', StockMovementType::Waste)->sole();
        $this->assertSame('Busuk', $movement->note);
        $this->assertTrue($movement->user->is($this->manager));
    }

    public function test_transfer_moves_stock_and_cost_between_outlets(): void
    {
        $owner = User::where('email', 'pabalu.owner@gmail.com')->sole();
        $kolaka = Outlet::where('code', 'KLK')->sole();
        $coffee = $this->ingredient('Biji Kopi Arabika');

        app(StockOperations::class)->transfer($owner, $this->kendari, [
            'to_outlet_id' => $kolaka->id,
            'items' => [['ingredient_id' => $coffee->id, 'quantity' => 1000]],
        ]);

        $this->assertEqualsWithDelta(2000, $this->stock('Biji Kopi Arabika'), 0.001);
        $this->assertEqualsWithDelta(4000, $this->stock('Biji Kopi Arabika', $kolaka), 0.001);
        $this->assertEqualsWithDelta(180, $this->ledger->averageCost($kolaka, $coffee), 0.0001);

        // Manager Kendari tidak punya akses ke Kolaka.
        $this->assertInvalid('to_outlet_id', fn () => app(StockOperations::class)->transfer($this->manager, $this->kendari, [
            'to_outlet_id' => $kolaka->id, 'items' => [['ingredient_id' => $coffee->id, 'quantity' => 1]],
        ]));
        $this->assertInvalid('to_outlet_id', fn () => app(StockOperations::class)->transfer($owner, $this->kendari, [
            'to_outlet_id' => $this->kendari->id, 'items' => [['ingredient_id' => $coffee->id, 'quantity' => 1]],
        ]));
    }

    public function test_stock_count_books_only_counted_differences(): void
    {
        $ops = app(StockOperations::class);
        $count = $ops->startCount($this->manager, $this->kendari);
        $this->assertInvalid('count', fn () => $ops->startCount($this->manager, $this->kendari));

        $lemonItem = $count->items()->where('ingredient_id', $this->ingredient('Lemon')->id)->sole();
        $eggItem = $count->items()->where('ingredient_id', $this->ingredient('Telur')->id)->sole();

        $ops->completeCount($this->manager, $count, [$lemonItem->id => '27', $eggItem->id => '']);

        $this->assertEqualsWithDelta(27, $this->stock('Lemon'), 0.001);
        $this->assertEqualsWithDelta(90, $this->stock('Telur'), 0.001);
        $this->assertEqualsWithDelta(-3, (float) $lemonItem->fresh()->difference, 0.001);
        $this->assertSame(StockCount::COMPLETED, $count->fresh()->status);
        $this->assertInvalid('count', fn () => $ops->completeCount($this->manager, $count->fresh(), []));
    }

    public function test_inventory_and_recipe_pages_respect_permissions(): void
    {
        $this->actingAs($this->manager)->get(route('inventory.index'))->assertOk()->assertSee('Biji Kopi Arabika');
        $this->actingAs($this->manager)->get(route('inventory.counts.index'))->assertOk();
        $this->actingAs($this->manager)->get(route('recipes.index', ['tab' => 'menu']))->assertOk()->assertSee('Kopi Susu Gula Aren');
        $this->actingAs($this->kasir)->get(route('inventory.index'))->assertForbidden();
        $this->actingAs($this->kasir)->get(route('recipes.index'))->assertForbidden();
    }

    public function test_recipe_editor_saves_rows_and_base_unit_is_locked_after_movements(): void
    {
        $this->actingAs($this->manager);
        $item = MenuItem::where('sku', 'MKN-003')->sole(); // Ayam Geprek, belum ada resep

        Volt::test('pages.recipes.index')
            ->call('editRecipe', 'menu', $item->id)
            ->set('recipeRows.0.ingredient_id', (string) $this->ingredient('Beras')->id)
            ->set('recipeRows.0.quantity', '180')
            ->call('saveRecipe')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(180, (float) $item->recipeItems()->sole()->quantity, 0.001);

        Volt::test('pages.recipes.index')
            ->call('editIngredient', $this->ingredient('Beras')->id)
            ->set('baseUnit', 'pcs')
            ->call('saveIngredient')
            ->assertHasErrors('baseUnit');
    }
}
