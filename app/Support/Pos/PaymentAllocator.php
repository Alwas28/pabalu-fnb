<?php

namespace App\Support\Pos;

use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Alokasi split payment ke tagihan. Non-tunai dihitung lebih dulu dan tidak boleh
 * melebihi tagihan; tunai menutup sisa tagihan dan kelebihannya menjadi kembalian.
 */
class PaymentAllocator
{
    /**
     * @param  list<array{method: string, amount: int|string, reference?: ?string}>  $payments
     * @return list<array{method: PaymentMethod, amount: int, tendered_amount: int, change_amount: int, reference: ?string}>
     *
     * @throws ValidationException
     */
    public static function allocate(array $payments, int $total): array
    {
        $lines = array_map(fn (array $p) => [
            'method' => PaymentMethod::from($p['method']),
            'tendered' => (int) $p['amount'],
            'reference' => ($p['reference'] ?? null) ?: null,
        ], $payments);

        $nonCash = array_sum(array_map(fn ($l) => $l['method'] === PaymentMethod::Cash ? 0 : $l['tendered'], $lines));
        $cash = array_sum(array_map(fn ($l) => $l['method'] === PaymentMethod::Cash ? $l['tendered'] : 0, $lines));

        if ($nonCash > $total) {
            throw ValidationException::withMessages(['payments' => 'Pembayaran non-tunai melebihi total tagihan. Kembalian hanya dari pembayaran tunai.']);
        }

        if ($nonCash + $cash < $total) {
            throw ValidationException::withMessages(['payments' => 'Pembayaran kurang dari total tagihan.']);
        }

        $remaining = $total - $nonCash;
        $allocated = [];

        foreach ($lines as $line) {
            $amount = $line['tendered'];
            $change = 0;

            if ($line['method'] === PaymentMethod::Cash) {
                $amount = min($line['tendered'], $remaining);
                $change = $line['tendered'] - $amount;
                $remaining -= $amount;
            }

            $allocated[] = [
                'method' => $line['method'],
                'amount' => $amount,
                'tendered_amount' => $line['tendered'],
                'change_amount' => $change,
                'reference' => $line['reference'],
            ];
        }

        return $allocated;
    }

    /**
     * Aturan validasi pembayaran untuk outlet.
     *
     * @param  list<string>  $methods
     * @return array<string, mixed>
     */
    public static function rules(array $methods, bool $required = true): array
    {
        return [
            'payments' => [$required ? 'required' : 'nullable', 'array', 'min:1', 'max:10'],
            'payments.*.method' => ['required', Rule::in($methods)],
            'payments.*.amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'payments.required' => 'Tambahkan minimal satu pembayaran.',
            'payments.*.method.in' => 'Metode pembayaran ini tidak aktif di outlet.',
            'payments.*.amount.min' => 'Nominal pembayaran harus lebih dari 0.',
        ];
    }
}
