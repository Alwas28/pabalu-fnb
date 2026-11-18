<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CashShift;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\RefundRequest;
use App\Models\User;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\RefundService;
use App\Support\Pos\ShiftManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private User $owner;

    private Outlet $kendari;

    private CashShift $shift;

    private RefundService $refunds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->kasir = User::where('email', 'pabalu.kasir@gmail.com')->sole();
        $this->owner = User::where('email', 'pabalu.owner@gmail.com')->sole();
        $this->kendari = Outlet::where('code', 'KDI')->sole();
        $this->shift = app(ShiftManager::class)->open($this->kasir, $this->kendari, ['opening_cash' => 100000]);
        $this->refunds = app(RefundService::class);
    }

    /** 2 air mineral = 13.860, dibayar tunai 10.000 + QRIS 3.860. */
    private function sale(): Order
    {
        return app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'takeaway',
            'items' => [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 10000], ['method' => 'qris', 'amount' => 3860]],
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

    public function test_staff_refund_waits_for_owner_then_reduces_drawer_cash(): void
    {
        $order = $this->sale();

        $request = $this->refunds->request($this->kasir, $order, ['type' => 'refund', 'amount' => 6000, 'method' => 'cash', 'reason' => 'Air tumpah']);

        $this->assertSame(ApprovalStatus::Pending, $request->status);
        $this->assertSame(0, $order->fresh()->refunded_amount);

        $this->refunds->approve($this->owner, $request, 'Ok');

        $order->refresh();
        $this->assertSame(6000, $order->refunded_amount);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->payment_status);
        $this->assertSame($this->shift->id, $order->refunds()->sole()->cash_shift_id);

        $summary = app(ShiftManager::class)->summary($this->shift);
        $this->assertSame(6000, $summary['cash_refunds']);
        $this->assertSame(100000 + 10000 - 6000, $summary['expected_cash']);
    }

    public function test_void_returns_every_payment_and_cancels_the_order(): void
    {
        $order = $this->sale();

        $request = $this->refunds->request($this->kasir, $order, ['type' => 'void', 'reason' => 'Salah input menu']);
        $this->assertSame(13860, $request->amount);
        $this->refunds->approve($this->owner, $request);

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Refunded, $order->payment_status);
        $this->assertSame(['cash' => 10000, 'qris' => 3860], $order->refunds()->get()->pluck('amount', 'method.value')->all());
        $this->assertTrue($order->items()->get()->every(fn ($i) => $i->kitchen_status === KitchenItemStatus::Cancelled));
        $this->assertTrue($order->canceller->is($this->owner));
    }

    public function test_owner_request_is_applied_immediately(): void
    {
        $order = $this->sale();

        $request = $this->refunds->request($this->owner, $order, ['type' => 'refund', 'amount' => 13860, 'method' => 'qris', 'reason' => 'Komplain']);

        $this->assertSame(ApprovalStatus::Approved, $request->status);
        $this->assertSame(PaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertNull($order->refunds()->sole()->cash_shift_id); // non-tunai tidak memengaruhi laci
    }

    public function test_only_owner_can_decide(): void
    {
        $order = $this->sale();
        $request = $this->refunds->request($this->kasir, $order, ['type' => 'void', 'reason' => 'x']);

        foreach (['pabalu.supervisor@gmail.com', 'pabalu.manager@gmail.com'] as $email) {
            try {
                $this->refunds->approve(User::where('email', $email)->sole(), $request);
                $this->fail('Hanya owner yang boleh menyetujui.');
            } catch (AuthorizationException) {
            }
        }

        $this->actingAs(User::where('email', 'pabalu.manager@gmail.com')->sole())->get(route('approvals.index'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('approvals.index'))->assertOk()->assertSee($order->number);

        $this->assertInvalid('note', fn () => $this->refunds->reject($this->owner, $request, ''));
        $this->refunds->reject($this->owner, $request, 'Tidak sesuai kebijakan');
        $this->assertSame(ApprovalStatus::Rejected, $request->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_invalid_requests_are_rejected(): void
    {
        $order = $this->sale();

        $this->assertInvalid('amount', fn () => $this->refunds->request($this->kasir, $order, ['type' => 'refund', 'amount' => 99999, 'method' => 'cash', 'reason' => 'x']));

        $this->refunds->request($this->kasir, $order, ['type' => 'refund', 'amount' => 1000, 'method' => 'cash', 'reason' => 'x']);
        $this->assertInvalid('type', fn () => $this->refunds->request($this->kasir, $order, ['type' => 'refund', 'amount' => 1000, 'method' => 'cash', 'reason' => 'y']));

        $this->refunds->approve($this->owner, RefundRequest::sole());
        $this->assertInvalid('type', fn () => $this->refunds->request($this->kasir, $order->fresh(), ['type' => 'void', 'reason' => 'z']));

        // Open bill yang belum dibayar memakai pembatalan biasa, bukan refund.
        $openBill = app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'dine_in', 'pay_later' => true,
            'dining_table_id' => $this->kendari->diningTables()->value('id'),
            'items' => [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => 1]],
        ]);
        $this->assertInvalid('type', fn () => $this->refunds->request($this->kasir, $openBill, ['type' => 'void', 'reason' => 'x']));
    }

    public function test_kasir_submits_request_from_order_page(): void
    {
        $order = $this->sale();
        $this->actingAs($this->kasir);

        Volt::test('pages.orders.show', ['order' => $order])
            ->set('refundType', 'refund')
            ->set('refundAmount', '5000')
            ->set('refundMethod', 'cash')
            ->set('refundReason', 'Porsi kurang')
            ->call('requestRefund')
            ->assertHasNoErrors()
            ->assertSee('Menunggu');

        $this->assertSame(ApprovalStatus::Pending, RefundRequest::sole()->status);
    }
}
