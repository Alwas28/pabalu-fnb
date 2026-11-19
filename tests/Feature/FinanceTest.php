<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ExpenseCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\RefundService;
use App\Support\Pos\ShiftManager;
use App\Support\Reports\FinanceMetrics;
use App\Support\Reports\Period;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Kendari (pajak 10% + service 5%). Air mineral Rp6.000, HPP Rp2.000 (48.000 / 24).
 */
class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $kasir;

    private Outlet $kendari;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('email', 'pabalu.owner@gmail.com')->sole();
        $this->kasir = User::where('email', 'pabalu.kasir@gmail.com')->sole();
        $this->kendari = Outlet::where('code', 'KDI')->sole();
        $this->business = $this->kendari->business;
        app(ShiftManager::class)->open($this->kasir, $this->kendari, ['opening_cash' => 0]);
        app(ShiftManager::class)->open(User::where('email', 'pabalu.supervisor@gmail.com')->sole(), $this->kendari, ['opening_cash' => 0]);
    }

    private function sellWater(int $quantity, array $extra = [], ?User $cashier = null): Order
    {
        return app(PlaceOrder::class)->handle($cashier ?? $this->kasir, $this->kendari, $extra + [
            'service_type' => 'takeaway',
            'items' => [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => $quantity]],
            'payments' => [['method' => 'cash', 'amount' => 1000000]],
        ]);
    }

    private function metrics(): FinanceMetrics
    {
        return new FinanceMetrics($this->business->id, $this->business->outlets()->pluck('id')->all(), Period::make(null, null, $this->business->timezone));
    }

    public function test_summary_follows_metric_definitions(): void
    {
        $this->sellWater(10); // 60.000 + 3.000 + 6.300 = 69.300, HPP 20.000
        $discounted = $this->sellWater(5, ['discount_type' => 'amount', 'discount_value' => 5000], User::where('email', 'pabalu.supervisor@gmail.com')->sole());
        // subtotal 30.000 − diskon 5.000 = 25.000 → service 1.250 → pajak 2.625 → total 28.875, HPP 10.000

        // Refund seluruh order berdiskon: porsi penjualan = 25.000.
        app(RefundService::class)->request($this->owner, $discounted, ['type' => 'refund', 'amount' => 28875, 'method' => 'cash', 'reason' => 'Komplain']);

        $this->business->expenses()->create([
            'outlet_id' => null, 'expense_category_id' => $this->business->expenseCategories()->create(['name' => 'Listrik'])->id,
            'amount' => 15000, 'method' => 'bank_transfer', 'spent_at' => now($this->business->timezone)->toDateString(), 'description' => 'Token listrik',
        ]);

        $s = $this->metrics()->summary();
        $this->assertSame(2, $s['orders']);
        $this->assertSame(90000, $s['gross_sales']);
        $this->assertSame(5000, $s['discount']);
        $this->assertSame(60000, $s['net_sales']);        // 90.000 − 5.000 − 25.000
        $this->assertSame(30000, $s['cogs']);
        $this->assertSame(30000, $s['gross_profit']);
        $this->assertSame(15000, $s['expenses']);
        $this->assertSame(15000, $s['operating_profit']);
        $this->assertSame(6300 + 2625, $s['tax']);

        $cash = $this->metrics()->cashFlow();
        $this->assertSame(69300 + 28875, $cash['received']);
        $this->assertSame(28875, $cash['refunded']);
        $this->assertSame(69300 - 15000, $cash['net']);
    }

    public function test_void_and_open_bills_are_excluded_from_sales(): void
    {
        $voided = $this->sellWater(2);
        app(RefundService::class)->request($this->owner, $voided, ['type' => 'void', 'reason' => 'Salah']);

        app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'dine_in', 'pay_later' => true, 'dining_table_id' => $this->kendari->diningTables()->value('id'),
            'items' => [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => 1]],
        ]);

        $s = $this->metrics()->summary();
        $this->assertSame(0, $s['orders']);
        $this->assertSame(1, $s['void_count']);
    }

    public function test_finance_is_owner_only_while_expenses_need_record_permission(): void
    {
        $manager = User::where('email', 'pabalu.manager@gmail.com')->sole();
        $this->actingAs($manager)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('expenses.index'))->assertOk();
        $this->actingAs(User::where('email', 'pabalu.kasir@gmail.com')->sole())->get(route('expenses.index'))->assertForbidden();

        $this->actingAs($this->owner)->get(route('finance.index'))->assertOk()->assertSee('Laba Rugi');
        $this->actingAs($this->owner)->get(route('expenses.index'))->assertOk()->assertSee('Daftar Pengeluaran');
        $this->assertSame(8, $this->business->expenseCategories()->whereNotNull('template_id')->count()); // kategori bawaan
        $this->assertTrue($this->business->expenseCategories()->where('name', 'Bahan Baku')->exists());
    }

    public function test_manager_records_only_outlet_expenses_without_seeing_shared_costs(): void
    {
        ExpenseCategory::syncTemplatesFor($this->business);
        $shared = $this->business->expenses()->create([
            'expense_category_id' => $this->business->expenseCategories()->where('name', 'Sewa Tempat')->value('id'),
            'amount' => 3000000, 'method' => 'cash', 'spent_at' => now()->toDateString(), 'description' => 'Sewa kantor pusat', 'user_id' => $this->owner->id,
        ]);

        $manager = User::where('email', 'pabalu.manager@gmail.com')->sole();
        $this->actingAs($manager);

        $page = Volt::test('pages.expenses.index')
            ->assertDontSee('Sewa kantor pusat')
            ->dispatch('expense-open')
            ->assertSet('expenseOutletId', (string) $this->kendari->id)
            ->set('categoryId', (string) $this->business->expenseCategories()->where('name', 'Listrik & Air')->value('id'))
            ->set('amount', '250000')
            ->set('description', 'Token listrik');

        $page->set('expenseOutletId', '')->call('saveExpense')->assertHasErrors('expenseOutletId');
        $page->set('newCategory', 'Kategori Baru')->set('expenseOutletId', (string) $this->kendari->id)
            ->call('saveExpense')->assertHasErrors('newCategory');
        $page->set('newCategory', '')->call('saveExpense')->assertHasNoErrors();

        $this->assertSame($this->kendari->id, $this->business->expenses()->where('description', 'Token listrik')->sole()->outlet_id);

        $page->call('deleteExpense', $shared->id)->assertForbidden();
    }

    public function test_expense_page_records_edits_and_deletes_expenses(): void
    {
        $this->actingAs($this->owner);

        $page = Volt::test('pages.expenses.index')
            ->dispatch('expense-open')
            ->set('newCategory', 'Kebersihan')
            ->set('amount', '50000')
            ->set('description', 'Sabun & tisu')
            ->call('saveExpense')
            ->assertHasNoErrors();

        $expense = $this->business->expenses()->sole();
        $this->assertSame('Kebersihan', $expense->category->name);
        $this->assertSame(50000, $this->metrics()->expenseTotal());

        $page->call('editExpense', $expense->id)
            ->assertSet('amount', '50000')
            ->set('amount', '65000')
            ->call('saveExpense')
            ->assertHasNoErrors();

        $this->assertSame(65000, $expense->fresh()->amount);
        $this->assertSame(1, $this->business->expenses()->count());

        $page->call('deleteExpense', $expense->id);
        $this->assertSame(0, $this->metrics()->expenseTotal());
    }
}
