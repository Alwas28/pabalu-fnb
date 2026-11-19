<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Analytics\OutboxDispatcher;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\ShiftManager;
use App\Support\Purchasing\QuickPurchaseService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AnalyticsOutboxTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://analytics.example.test/ingest';

    private User $kasir;

    private User $owner;

    private Outlet $kendari;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->kasir = User::where('email', 'pabalu.kasir@gmail.com')->sole();
        $this->owner = User::where('email', 'pabalu.owner@gmail.com')->sole();
        $this->kendari = Outlet::where('code', 'KDI')->sole();

        config([
            'services.analytics.endpoint' => self::ENDPOINT,
            'services.analytics.token' => 'token-rahasia',
            'services.analytics.signing_secret' => 'kunci-hmac',
        ]);
    }

    private function sellWater(): Order
    {
        return app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'takeaway',
            'items' => [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 100000]],
        ]);
    }

    private function newExpense(): Expense
    {
        $business = $this->kendari->business;
        ExpenseCategory::syncTemplatesFor($business);

        return $business->expenses()->create([
            'outlet_id' => $this->kendari->id,
            'expense_category_id' => $business->expenseCategories()->where('name', 'Listrik & Air')->value('id'),
            'amount' => 250000, 'method' => 'cash', 'spent_at' => now()->toDateString(), 'description' => 'Token listrik',
        ]);
    }

    public function test_business_actions_record_events_after_commit(): void
    {
        $shift = app(ShiftManager::class)->open($this->kasir, $this->kendari, ['opening_cash' => 0]);
        $order = $this->sellWater();

        $paid = OutboxEvent::where('type', 'order.paid')->sole();
        $this->assertSame($order->id, $paid->payload['order']['id']);
        $this->assertSame('KDI', $paid->payload['order']['outlet']['code']);
        $this->assertSame(2, $paid->payload['items'][0]['quantity']);
        $this->assertSame($order->total, $paid->payload['payments'][0]['amount']);

        $expense = $this->newExpense();
        $expense->delete();
        $this->assertSame(['expense.recorded', 'expense.deleted'], OutboxEvent::where('type', 'like', 'expense.%')->orderBy('id')->pluck('type')->all());
        $this->assertSame(250000, OutboxEvent::where('type', 'expense.deleted')->sole()->payload['expense']['amount']);

        $purchase = app(QuickPurchaseService::class)->record($this->owner, $this->kendari, [
            'purchased_at' => now($this->kendari->business->timezone)->toDateString(), 'method' => 'cash',
            'items' => [['ingredient_id' => Ingredient::where('name', 'Minyak Goreng')->value('id'), 'quantity' => '800', 'total_price' => '15000']],
        ]);
        app(QuickPurchaseService::class)->cancel($this->owner, $purchase);
        $this->assertEquals(800, OutboxEvent::where('type', 'quick_purchase.recorded')->sole()->payload['purchase']['items'][0]['quantity']);
        $this->assertTrue(OutboxEvent::where('type', 'quick_purchase.cancelled')->exists());

        app(ShiftManager::class)->close($this->kasir, $shift, ['actual_cash' => $order->total]);
        $this->assertSame($order->total, OutboxEvent::where('type', 'shift.closed')->sole()->payload['shift']['actual_cash']);
    }

    public function test_rolled_back_actions_and_disabled_endpoint_record_nothing(): void
    {
        try {
            DB::transaction(function () {
                $this->newExpense();

                throw new RuntimeException('gagal');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, OutboxEvent::count());

        config(['services.analytics.endpoint' => null]);
        $this->newExpense();
        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_dispatcher_sends_signed_batch_and_retries_failures(): void
    {
        $this->newExpense();
        $this->newExpense();

        Http::fakeSequence(self::ENDPOINT)->push('maintenance', 503)->push(['ok' => true]);
        $result = app(OutboxDispatcher::class)->dispatch();

        $this->assertSame(2, $result['failed']);
        $this->assertSame(0, OutboxEvent::due()->count()); // menunggu jeda percobaan ulang
        $this->assertSame(1, OutboxEvent::first()->attempts);
        $this->assertStringContainsString('503', OutboxEvent::first()->last_error);

        $this->travel(3)->minutes();

        $this->artisan('pabalu:outbox-dispatch')->assertSuccessful();

        $this->assertSame(0, OutboxEvent::whereNull('dispatched_at')->count());
        Http::assertSent(function (Request $request) {
            $events = $request->data()['events'] ?? [];

            return $request->url() === self::ENDPOINT
                && $request->hasHeader('Authorization', 'Bearer token-rahasia')
                && $request->header('X-Pabalu-Signature')[0] === 'sha256='.hash_hmac('sha256', $request->body(), 'kunci-hmac')
                && count($events) === 2
                && $events[0]['type'] === 'expense.recorded'
                && $events[0]['id'] === OutboxEvent::orderBy('id')->value('uuid');
        });
    }
}
