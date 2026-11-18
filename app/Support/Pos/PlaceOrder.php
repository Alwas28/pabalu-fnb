<?php

namespace App\Support\Pos;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceType;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Membuat order dari kasir (web maupun API) — satu-satunya jalur pembuatan order.
 *
 * - Wajib ada shift berjalan milik kasir di outlet.
 * - Harga, pajak, service charge & total dihitung ulang di server (CartResolver + OrderTotals).
 * - Bayar langsung: split payment, non-tunai tidak boleh melebihi tagihan, kelebihan tunai = kembalian.
 * - Bayar nanti (pay_later): hanya dine-in di outlet yang mengizinkan; order disimpan sebagai open bill
 *   tanpa pembayaran & diskon (keduanya diproses saat pelunasan lewat SettleOrder).
 * - Meja: outlet yang memakai meja memilih dari daftar meja (dining_table_id).
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

        $shift = ShiftManager::require($cashier, $outlet);
        $payLater = filter_var($input['pay_later'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $data = Validator::make($input, $this->rules($outlet, $input, $payLater), $this->messages())->validate();

        $table = isset($data['dining_table_id']) ? $outlet->diningTables()->find($data['dining_table_id']) : null;

        if ($payLater && $table && $outlet->tracksTableStatus() && $table->openOrders()->exists()) {
            throw ValidationException::withMessages([
                'dining_table_id' => "Meja {$table->name} masih punya pesanan terbuka. Tambahkan item ke pesanan tersebut.",
            ]);
        }

        [$discountType, $discountValue] = $payLater ? [null, 0.0] : OrderWriter::discount($cashier, $data);

        $lines = $this->cart->resolve($outlet, $data['items']);
        $totals = OrderTotals::calculate($outlet, CartResolver::subtotal($lines), $discountType, $discountValue);
        $payments = $payLater ? [] : PaymentAllocator::allocate($data['payments'], $totals->total);

        return DB::transaction(function () use ($cashier, $outlet, $shift, $data, $payLater, $table, $discountType, $discountValue, $lines, $totals, $payments) {
            // Kunci outlet agar penomoran order harian tidak bentrok saat kasir bertransaksi bersamaan.
            $outlet = Outlet::whereKey($outlet->id)->lockForUpdate()->firstOrFail();
            $now = now();

            $order = Order::create([
                'business_id' => $outlet->business_id,
                'outlet_id' => $outlet->id,
                'cashier_id' => $cashier->id,
                'cash_shift_id' => $shift->id,
                'number' => $this->nextNumber($outlet),
                'receipt_token' => Str::random(32),
                'client_reference' => $data['client_reference'] ?? null,
                'service_type' => $data['service_type'],
                'table_number' => $table?->name,
                'dining_table_id' => $table?->id,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => OrderStatus::Confirmed,
                'payment_status' => PaymentStatus::Pending,
                'placed_at' => $now,
            ] + OrderWriter::totalsAttributes($totals, $discountType, $discountValue, $data['discount_reason'] ?? null));

            OrderWriter::writeLines($order, $lines);

            if (! $payLater) {
                OrderWriter::writePayments($order, $payments, $cashier, $shift);
            }

            OrderWriter::history($order, null, OrderStatus::Confirmed, $cashier, $payLater ? 'Pesanan dibuka (bayar nanti)' : 'Order dibuat & dibayar di kasir');
            OrderWriter::occupy($table);

            // Tanpa layar dapur, item langsung tersaji → order lunas langsung Selesai.
            KitchenFlow::sync($order, $cashier);

            return $order;
        });
    }

    /** Nomor order per outlet per hari (zona waktu bisnis), mis. KDI-261118-0007. */
    private function nextNumber(Outlet $outlet): string
    {
        $timezone = $outlet->business->timezone ?: config('app.timezone');
        $prefix = $outlet->code.'-'.now($timezone)->format('ymd').'-';

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
    private function rules(Outlet $outlet, array $input, bool $payLater): array
    {
        $isDineIn = ($input['service_type'] ?? null) === ServiceType::DineIn->value;
        $tableRule = Rule::exists(DiningTable::class, 'id')->where('outlet_id', $outlet->id)->where('is_active', true);

        return [
            'client_reference' => ['nullable', 'string', 'max:64'],
            'pay_later' => ['nullable', 'boolean', Rule::when($payLater, [
                fn ($attribute, $value, $fail) => $outlet->allowsPayLater() && $isDineIn
                    ? null
                    : $fail('Bayar nanti hanya untuk pesanan dine-in di outlet yang mengizinkannya.'),
            ])],
            'service_type' => ['required', Rule::in($outlet->service_types->map->value->all())],
            'dining_table_id' => $outlet->usesTables() && $isDineIn
                ? [$payLater ? 'required' : 'nullable', 'integer', $tableRule]
                : ['prohibited'],
            'customer_name' => [$payLater && ! $outlet->usesTables() ? 'required' : 'nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            ...OrderWriter::discountRules($input),
            ...CartResolver::rules(),
            ...($payLater
                ? ['payments' => ['prohibited']]
                : PaymentAllocator::rules($outlet->payment_methods->map->value->all())),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'service_type.in' => 'Jenis layanan ini tidak aktif di outlet.',
            'dining_table_id.prohibited' => 'Outlet ini tidak menggunakan manajemen meja.',
            'dining_table_id.required' => 'Pilih meja untuk pesanan bayar nanti.',
            'dining_table_id.exists' => 'Meja tidak ditemukan di outlet ini.',
            'customer_name.required' => 'Isi nama pelanggan untuk pesanan bayar nanti.',
            'payments.prohibited' => 'Pesanan bayar nanti dibayar saat pelunasan.',
            ...CartResolver::messages(),
            ...PaymentAllocator::messages(),
        ];
    }
}
