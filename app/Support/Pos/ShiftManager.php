<?php

namespace App\Support\Pos;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\ShiftStatus;
use App\Models\CashShift;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shift kasir (Blueprint Khusus F&B bagian 5 & 6): wajib dibuka sebelum bertransaksi.
 *
 * Kas ekspektasi = modal awal + pembayaran tunai yang diterima di shift (setelah kembalian)
 *                  + kas masuk − kas keluar. Selisih = kas aktual − kas ekspektasi.
 */
class ShiftManager
{
    public const NO_SHIFT_MESSAGE = 'Buka shift kasir terlebih dahulu sebelum bertransaksi.';

    /**
     * Shift berjalan milik kasir di outlet, atau gagal validasi bila belum dibuka.
     *
     * @throws ValidationException
     */
    public static function require(User $user, Outlet $outlet): CashShift
    {
        return CashShift::currentFor($user, $outlet)
            ?? throw ValidationException::withMessages(['shift' => self::NO_SHIFT_MESSAGE]);
    }

    /**
     * @param  array<string, mixed>  $input  opening_cash, opening_note
     *
     * @throws ValidationException
     */
    public function open(User $user, Outlet $outlet, array $input): CashShift
    {
        $data = Validator::make($input, [
            'opening_cash' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'opening_note' => ['nullable', 'string', 'max:255'],
        ], [], ['opening_cash' => 'modal awal'])->validate();

        return DB::transaction(function () use ($user, $outlet, $data) {
            // Kunci outlet agar dua permintaan bersamaan tidak membuka dua shift.
            Outlet::whereKey($outlet->id)->lockForUpdate()->first();

            if (CashShift::currentFor($user, $outlet)) {
                throw ValidationException::withMessages(['shift' => 'Kamu masih punya shift yang berjalan di outlet ini.']);
            }

            return CashShift::create([
                'business_id' => $outlet->business_id,
                'outlet_id' => $outlet->id,
                'user_id' => $user->id,
                'status' => ShiftStatus::Open,
                'opening_cash' => (int) $data['opening_cash'],
                'opening_note' => $data['opening_note'] ?? null,
                'opened_at' => now(),
            ]);
        });
    }

    /**
     * Kas masuk / keluar di luar penjualan, wajib dengan alasan.
     *
     * @param  array<string, mixed>  $input  type, amount, reason
     *
     * @throws ValidationException
     */
    public function recordMovement(User $user, CashShift $shift, array $input): void
    {
        $this->ensureOpen($shift);

        $data = Validator::make($input, [
            'type' => ['required', Rule::enum(CashMovementType::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['amount' => 'nominal', 'reason' => 'alasan'])->validate();

        if ($data['type'] === CashMovementType::Out->value && $data['amount'] > $this->summary($shift)['expected_cash']) {
            throw ValidationException::withMessages(['amount' => 'Kas keluar melebihi uang di laci.']);
        }

        $shift->movements()->create($data + ['user_id' => $user->id]);
    }

    /**
     * Tutup shift: catat kas aktual, hitung selisih, bekukan rekap.
     *
     * @param  array<string, mixed>  $input  actual_cash, closing_note
     *
     * @throws ValidationException
     */
    public function close(User $user, CashShift $shift, array $input): CashShift
    {
        $this->ensureOpen($shift);

        $data = Validator::make($input, [
            'actual_cash' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'closing_note' => ['nullable', 'string', 'max:500'],
        ], [], ['actual_cash' => 'kas aktual'])->validate();

        return DB::transaction(function () use ($user, $shift, $data) {
            $shift = CashShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $this->ensureOpen($shift);

            $summary = $this->summary($shift);
            $actual = (int) $data['actual_cash'];
            $difference = $actual - $summary['expected_cash'];

            if ($difference !== 0 && blank($data['closing_note'] ?? null)) {
                throw ValidationException::withMessages(['closing_note' => 'Jelaskan penyebab selisih kas.']);
            }

            $shift->update([
                'status' => ShiftStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $user->id,
                'expected_cash' => $summary['expected_cash'],
                'actual_cash' => $actual,
                'cash_difference' => $difference,
                'closing_note' => $data['closing_note'] ?? null,
                'summary' => $summary,
            ]);

            return $shift;
        });
    }

    /**
     * Rekap shift. Untuk shift yang sudah ditutup, rekap yang dibekukan saat tutup dipakai.
     *
     * @return array{opening_cash: int, cash_sales: int, cash_in: int, cash_out: int, expected_cash: int, payments_total: int, orders_paid: int, orders_opened: int, discount_total: int, open_bills: int, payments_by_method: array<string, int>}
     */
    public function summary(CashShift $shift): array
    {
        if (! $shift->isOpen() && $shift->summary) {
            return $shift->summary;
        }

        $byMethod = $shift->payments()
            ->selectRaw('method, sum(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method')
            ->map(fn ($v) => (int) $v)
            ->all();

        $movements = $shift->movements()->reorder()->selectRaw('type, sum(amount) as total')->groupBy('type')->pluck('total', 'type');
        $cashIn = (int) ($movements[CashMovementType::In->value] ?? 0);
        $cashOut = (int) ($movements[CashMovementType::Out->value] ?? 0);
        $cashSales = $byMethod[PaymentMethod::Cash->value] ?? 0;

        $paidOrderIds = $shift->payments()->distinct()->pluck('order_id');

        return [
            'opening_cash' => $shift->opening_cash,
            'cash_sales' => $cashSales,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_cash' => $shift->opening_cash + $cashSales + $cashIn - $cashOut,
            'payments_total' => array_sum($byMethod),
            'orders_paid' => $paidOrderIds->count(),
            'orders_opened' => $shift->orders()->count(),
            'discount_total' => (int) Order::whereIn('id', $paidOrderIds)->sum('discount_amount'),
            'open_bills' => Order::where('outlet_id', $shift->outlet_id)->open()->count(),
            'payments_by_method' => $byMethod,
        ];
    }

    private function ensureOpen(CashShift $shift): void
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'Shift ini sudah ditutup.']);
        }
    }
}
