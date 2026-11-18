<?php

namespace App\Support\Pos;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Membuat order yang langsung dibayar dari kasir (web maupun API) — satu-satunya jalur pembuatan order.
 *
 * - Harga, pajak, service charge & total dihitung ulang di server (CartResolver + OrderTotals).
 * - Split payment: beberapa metode sekaligus. Non-tunai tidak boleh melebihi sisa tagihan;
 *   kelebihan tunai menjadi kembalian.
 * - Diskon hanya untuk pengguna dengan izin menyetujui transaksi (Supervisor ke atas).
 * - Idempoten lewat client_reference: kiriman ulang dari perangkat mengembalikan order yang sama.
 */
class PlaceOrder
{
    public function __construct(private CartResolver $cart) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(User $cashier, Outlet $outlet, array $input): Order
    {
        if (! empty($input['client_reference'])) {
            $existing = Order::where('business_id', $outlet->business_id)
                ->where('client_reference', $input['client_reference'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $data = Validator::make($input, $this->rules($outlet, $input), $this->messages())->validate();

        $discountType = isset($data['discount_type']) ? DiscountType::from($data['discount_type']) : null;
        $discountValue = (float) ($data['discount_value'] ?? 0);

        if ($discountType && $discountValue > 0 && ! $cashier->hasPermission(Permission::ApproveTransactions)) {
            throw ValidationException::withMessages(['discount_value' => 'Kamu tidak berhak memberikan diskon. Minta bantuan supervisor.']);
        }

        if (! $discountType || $discountValue <= 0) {
            $discountType = null;
            $discountValue = 0;
        }

        $lines = $this->cart->resolve($outlet, $data['items']);
        $totals = OrderTotals::calculate($outlet, CartResolver::subtotal($lines), $discountType, $discountValue);
        $payments = $this->allocatePayments($data['payments'], $totals->total);

        return DB::transaction(function () use ($cashier, $outlet, $data, $discountType, $discountValue, $lines, $totals, $payments) {
            // Kunci outlet agar penomoran order harian tidak bentrok saat kasir bertransaksi bersamaan.
            $outlet = Outlet::whereKey($outlet->id)->lockForUpdate()->firstOrFail();
            $now = now();

            $order = Order::create([
                'business_id' => $outlet->business_id,
                'outlet_id' => $outlet->id,
                'cashier_id' => $cashier->id,
                'number' => $this->nextNumber($outlet),
                'receipt_token' => Str::random(32),
                'client_reference' => $data['client_reference'] ?? null,
                'service_type' => $data['service_type'],
                'table_number' => $data['table_number'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => OrderStatus::Completed,
                'payment_status' => PaymentStatus::Paid,
                'subtotal' => $totals->subtotal,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $totals->discount,
                'discount_reason' => $discountType ? ($data['discount_reason'] ?? null) : null,
                'service_charge_rate' => $totals->serviceChargeRate,
                'service_charge_amount' => $totals->serviceCharge,
                'tax_rate' => $totals->taxRate,
                'prices_include_tax' => $totals->pricesIncludeTax,
                'tax_amount' => $totals->tax,
                'total' => $totals->total,
                'paid_amount' => array_sum(array_column($payments, 'amount')),
                'change_amount' => array_sum(array_column($payments, 'change_amount')),
                'placed_at' => $now,
                'completed_at' => $now,
            ]);

            foreach ($lines as $line) {
                $item = $order->items()->create([
                    'menu_item_id' => $line->menuItem->id,
                    'menu_variant_id' => $line->variant?->id,
                    'name' => $line->menuItem->name,
                    'variant_name' => $line->variant?->name,
                    'unit_price' => $line->unitPrice,
                    'quantity' => $line->quantity,
                    'line_total' => $line->lineTotal(),
                    'notes' => $line->notes,
                ]);

                foreach ($line->modifiers as $modifier) {
                    $item->modifiers()->create([
                        'modifier_id' => $modifier->id,
                        'group_name' => $modifier->group->name,
                        'name' => $modifier->name,
                        'price' => $modifier->price,
                    ]);
                }
            }

            foreach ($payments as $payment) {
                $order->payments()->create($payment + [
                    'status' => PaymentStatus::Paid,
                    'received_by' => $cashier->id,
                    'paid_at' => $now,
                ]);
            }

            $order->statusHistories()->create([
                'user_id' => $cashier->id,
                'from_status' => null,
                'to_status' => OrderStatus::Completed,
                'note' => 'Order dibuat & dibayar di kasir',
                'created_at' => $now,
            ]);

            return $order;
        });
    }

    /**
     * Alokasikan pembayaran ke tagihan. Non-tunai dihitung lebih dulu dan harus pas;
     * tunai menutup sisa tagihan dan kelebihannya menjadi kembalian.
     *
     * @param  list<array{method: string, amount: int|string, reference?: ?string}>  $payments
     * @return list<array{method: PaymentMethod, amount: int, tendered_amount: int, change_amount: int, reference: ?string}>
     *
     * @throws ValidationException
     */
    private function allocatePayments(array $payments, int $total): array
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

    /** Nomor order per outlet per hari (zona waktu bisnis), mis. KDI-261118-0007. */
    private function nextNumber(Outlet $outlet): string
    {
        $timezone = $outlet->business->timezone ?: config('app.timezone');
        $today = now($timezone);
        $prefix = $outlet->code.'-'.$today->format('ymd').'-';

        $last = Order::where('outlet_id', $outlet->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $sequence = $last ? (int) Str::afterLast($last, '-') + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function rules(Outlet $outlet, array $input): array
    {
        $serviceTypes = $outlet->service_types->map->value->all();
        $methods = $outlet->payment_methods->map->value->all();

        return [
            'client_reference' => ['nullable', 'string', 'max:64'],
            'service_type' => ['required', Rule::in($serviceTypes)],
            'table_number' => [$outlet->usesTables() ? 'nullable' : 'prohibited', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],

            'discount_type' => ['nullable', Rule::enum(DiscountType::class)],
            'discount_value' => ['nullable', 'numeric', 'min:0', Rule::when(($input['discount_type'] ?? null) === DiscountType::Percent->value, ['max:100'])],
            'discount_reason' => ['nullable', 'string', 'max:255'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.modifier_ids' => ['nullable', 'array', 'max:30'],
            'items.*.modifier_ids.*' => ['integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],

            'payments' => ['required', 'array', 'min:1', 'max:10'],
            'payments.*.method' => ['required', Rule::in($methods)],
            'payments.*.amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'service_type.in' => 'Jenis layanan ini tidak aktif di outlet.',
            'table_number.prohibited' => 'Outlet ini tidak menggunakan manajemen meja.',
            'items.required' => 'Keranjang masih kosong.',
            'items.min' => 'Keranjang masih kosong.',
            'payments.required' => 'Tambahkan minimal satu pembayaran.',
            'payments.*.method.in' => 'Metode pembayaran ini tidak aktif di outlet.',
            'payments.*.amount.min' => 'Nominal pembayaran harus lebih dari 0.',
        ];
    }
}
