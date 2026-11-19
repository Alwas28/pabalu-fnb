<?php

namespace App\Support\Purchasing;

use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\QuickPurchase;
use App\Models\User;
use App\Support\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Belanja Cepat: belanja bahan tanpa PO & supplier. Satu catatan menambah stok outlet
 * (HPP = harga beli per satuan dasar) sekaligus tercatat sebagai uang keluar di arus kas.
 */
class QuickPurchaseService
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $input  purchased_at, store_name, method, note,
     *                                       items[ingredient_id, quantity, unit ('base'|'purchase'), total_price]
     *
     * @throws ValidationException
     */
    public function record(User $user, Outlet $outlet, array $input): QuickPurchase
    {
        $data = Validator::make($input, [
            'purchased_at' => ['required', 'date', 'before_or_equal:'.now($outlet->business->timezone)->toDateString()],
            'store_name' => ['nullable', 'string', 'max:80'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct',
                Rule::exists('ingredients', 'id')->where('business_id', $outlet->business_id)->where('is_active', true)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'items.*.unit' => ['nullable', Rule::in(['base', 'purchase'])],
            'items.*.total_price' => ['required', 'integer', 'min:0', 'max:10000000000'],
        ], [
            'items.required' => 'Tambahkan minimal satu bahan.',
            'items.*.ingredient_id.distinct' => 'Bahan yang sama dicatat dua kali.',
            'purchased_at.before_or_equal' => 'Tanggal belanja tidak boleh di masa depan.',
        ], [
            'purchased_at' => 'tanggal', 'store_name' => 'tempat belanja', 'method' => 'metode bayar', 'note' => 'catatan',
            'items.*.ingredient_id' => 'bahan', 'items.*.quantity' => 'jumlah', 'items.*.total_price' => 'harga',
        ])->validate();

        $ingredients = Ingredient::whereIn('id', collect($data['items'])->pluck('ingredient_id'))->get()->keyBy('id');

        return DB::transaction(function () use ($user, $outlet, $data, $ingredients) {
            $purchase = $outlet->business->quickPurchases()->create([
                'outlet_id' => $outlet->id,
                'purchased_at' => $data['purchased_at'],
                'store_name' => $data['store_name'] ?? null,
                'method' => $data['method'],
                'total' => collect($data['items'])->sum(fn ($line) => (int) $line['total_price']),
                'note' => $data['note'] ?? null,
                'user_id' => $user->id,
            ]);

            foreach ($data['items'] as $index => $line) {
                $ingredient = $ingredients[(int) $line['ingredient_id']];
                $base = round((float) $line['quantity'] * $this->unitSize($ingredient, $line['unit'] ?? 'base', $index), 3);
                $cost = round((int) $line['total_price'] / $base, 4);

                $purchase->items()->create([
                    'ingredient_id' => $ingredient->id,
                    'base_quantity' => $base,
                    'total_price' => (int) $line['total_price'],
                    'unit_cost' => $cost,
                ]);

                $this->ledger->move($outlet, $ingredient, StockMovementType::QuickPurchase, $base, $cost, $purchase,
                    'Belanja cepat'.($purchase->store_name ? ' · '.$purchase->store_name : ''), $user);
            }

            return $purchase;
        });
    }

    /**
     * Batalkan belanja (mis. salah input): stok dikurangi kembali & tidak lagi dihitung di arus kas.
     *
     * @throws ValidationException
     */
    public function cancel(User $user, QuickPurchase $purchase): void
    {
        DB::transaction(function () use ($user, $purchase) {
            $purchase = QuickPurchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($purchase->isCancelled()) {
                throw ValidationException::withMessages(['purchase' => 'Belanja ini sudah dibatalkan.']);
            }

            foreach ($purchase->items()->with('ingredient')->get() as $item) {
                $this->ledger->move($purchase->outlet, $item->ingredient, StockMovementType::PurchaseReturn, -(float) $item->base_quantity,
                    reference: $purchase, note: 'Pembatalan belanja cepat', user: $user);
            }

            $purchase->forceFill(['cancelled_at' => now(), 'cancelled_by' => $user->id])->save();
        });
    }

    /**
     * @throws ValidationException
     */
    private function unitSize(Ingredient $ingredient, string $unit, int|string $index): float
    {
        if ($unit === 'base') {
            return 1.0;
        }

        if (! $ingredient->purchase_unit || (float) $ingredient->purchase_unit_size <= 0) {
            throw ValidationException::withMessages(["items.$index.unit" => "{$ingredient->name} belum punya satuan beli."]);
        }

        return (float) $ingredient->purchase_unit_size;
    }
}
