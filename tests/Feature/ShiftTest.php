<?php

namespace Tests\Feature;

use App\Enums\ShiftStatus;
use App\Models\CashShift;
use App\Models\MenuItem;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Pos\PlaceOrder;
use App\Support\Pos\ShiftManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    private Outlet $kendari;

    private ShiftManager $shifts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->kasir = User::where('email', 'pabalu.kasir@gmail.com')->sole();
        $this->kendari = Outlet::where('code', 'KDI')->sole();
        $this->shifts = app(ShiftManager::class);
    }

    /** 2 air mineral di Kendari = 13.860 */
    private function sell(array $payments): void
    {
        app(PlaceOrder::class)->handle($this->kasir, $this->kendari, [
            'service_type' => 'takeaway',
            'items' => [['menu_item_id' => MenuItem::where('sku', 'NKP-003')->value('id'), 'quantity' => 2]],
            'payments' => $payments,
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

    public function test_transactions_require_an_open_shift(): void
    {
        $this->assertInvalid('shift', fn () => $this->sell([['method' => 'cash', 'amount' => 20000]]));

        $this->shifts->open($this->kasir, $this->kendari, ['opening_cash' => 200000]);
        $this->sell([['method' => 'cash', 'amount' => 20000]]);

        $this->assertSame(1, CashShift::sole()->orders()->count());
    }

    public function test_a_cashier_cannot_open_two_shifts_at_the_same_outlet(): void
    {
        $this->shifts->open($this->kasir, $this->kendari, ['opening_cash' => 0]);

        $this->assertInvalid('shift', fn () => $this->shifts->open($this->kasir, $this->kendari, ['opening_cash' => 0]));
        $this->assertSame(1, CashShift::count());
    }

    public function test_expected_cash_counts_cash_sales_after_change_and_cash_movements(): void
    {
        $shift = $this->shifts->open($this->kasir, $this->kendari, ['opening_cash' => 200000]);

        $this->sell([['method' => 'cash', 'amount' => 20000]]);                                          // tunai 13.860
        $this->sell([['method' => 'cash', 'amount' => 5000], ['method' => 'qris', 'amount' => 8860]]);   // tunai 5.000 + QRIS
        $this->shifts->recordMovement($this->kasir, $shift, ['type' => 'in', 'amount' => 50000, 'reason' => 'Tambah uang kecil']);
        $this->shifts->recordMovement($this->kasir, $shift, ['type' => 'out', 'amount' => 15000, 'reason' => 'Beli es batu']);

        $summary = $this->shifts->summary($shift);

        $this->assertSame(18860, $summary['cash_sales']);
        $this->assertSame(200000 + 18860 + 50000 - 15000, $summary['expected_cash']);
        $this->assertSame(['cash' => 18860, 'qris' => 8860], $summary['payments_by_method']);
        $this->assertSame(27720, $summary['payments_total']);
        $this->assertSame(2, $summary['orders_paid']);

        $this->assertInvalid('amount', fn () => $this->shifts->recordMovement($this->kasir, $shift, ['type' => 'out', 'amount' => 999999, 'reason' => 'x']));
        $this->assertInvalid('reason', fn () => $this->shifts->recordMovement($this->kasir, $shift, ['type' => 'in', 'amount' => 1000, 'reason' => '']));
    }

    public function test_closing_records_difference_and_freezes_summary(): void
    {
        $shift = $this->shifts->open($this->kasir, $this->kendari, ['opening_cash' => 100000]);
        $this->sell([['method' => 'cash', 'amount' => 20000]]); // ekspektasi 113.860

        // Selisih wajib dijelaskan.
        $this->assertInvalid('closing_note', fn () => $this->shifts->close($this->kasir, $shift, ['actual_cash' => 110000]));

        $closed = $this->shifts->close($this->kasir, $shift, ['actual_cash' => 110000, 'closing_note' => 'Kembalian salah hitung']);

        $this->assertSame(ShiftStatus::Closed, $closed->status);
        $this->assertSame(113860, $closed->expected_cash);
        $this->assertSame(-3860, $closed->cash_difference);
        $this->assertTrue($closed->closer->is($this->kasir));

        // Shift tertutup: transaksi baru ditolak, rekap tidak berubah.
        $this->assertInvalid('shift', fn () => $this->sell([['method' => 'cash', 'amount' => 20000]]));
        $this->assertInvalid('shift', fn () => $this->shifts->close($this->kasir, $closed, ['actual_cash' => 0]));
        $this->assertSame(113860, $this->shifts->summary($closed->fresh())['expected_cash']);
    }

    public function test_exact_cash_count_needs_no_note(): void
    {
        $shift = $this->shifts->open($this->kasir, $this->kendari, ['opening_cash' => 50000]);

        $closed = $this->shifts->close($this->kasir, $shift, ['actual_cash' => 50000]);

        $this->assertSame(0, $closed->cash_difference);
    }

    public function test_shift_pages_respect_ownership(): void
    {
        $shift = $this->shifts->open($this->kasir, $this->kendari, ['opening_cash' => 0]);
        $otherKasir = User::factory()->create();
        $otherKasir->memberships()->create(['business_id' => $this->kendari->business_id, 'role' => 'kasir'])
            ->outlets()->attach($this->kendari->id);

        $this->actingAs($this->kasir)->get(route('shifts.show', $shift))->assertOk();
        $this->actingAs(User::where('email', 'pabalu.supervisor@gmail.com')->sole())->get(route('shifts.show', $shift))->assertOk();
        $this->actingAs($otherKasir)->get(route('shifts.show', $shift))->assertForbidden();
        $this->actingAs(User::where('email', 'pabalu.dapur@gmail.com')->sole())->get(route('shifts.index'))->assertForbidden();
    }
}
