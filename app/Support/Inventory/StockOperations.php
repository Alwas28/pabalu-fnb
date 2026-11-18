<?php

namespace App\Support\Inventory;

use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Operasi stok manual: waste, penyesuaian, transfer antar outlet, stok opname.
 * Semua wajib beralasan & tercatat pelakunya (Blueprint Khusus F&B bagian 14).
 */
class StockOperations
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * Waste / rusak / kedaluwarsa (stok berkurang).
     *
     * @param  array<string, mixed>  $input  ingredient_id, quantity, reason
     */
    public function waste(User $user, Outlet $outlet, array $input): void
    {
        $data = $this->validateLine($outlet, $input, positive: true);
        $ingredient = Ingredient::findOrFail($data['ingredient_id']);

        $this->ledger->move($outlet, $ingredient, StockMovementType::Waste, -(float) $data['quantity'], note: $data['reason'], user: $user);
    }

    /**
     * Penyesuaian manual (+ / −), mis. koreksi salah input. Bila menambah stok tanpa pembelian,
     * nilai per satuan bisa diisi agar HPP tetap akurat.
     *
     * @param  array<string, mixed>  $input  ingredient_id, quantity (boleh negatif), unit_cost?, reason
     */
    public function adjust(User $user, Outlet $outlet, array $input): void
    {
        $data = $this->validateLine($outlet, $input, positive: false, withCost: true);
        $ingredient = Ingredient::findOrFail($data['ingredient_id']);
        $quantity = (float) $data['quantity'];

        if ($quantity == 0.0) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah penyesuaian tidak boleh 0.']);
        }

        $cost = $quantity > 0 && isset($data['unit_cost']) && $data['unit_cost'] !== '' ? (float) $data['unit_cost'] : null;
        $this->ledger->move($outlet, $ingredient, StockMovementType::Adjustment, $quantity, $cost, note: $data['reason'], user: $user);
    }

    /**
     * Transfer bahan ke outlet lain di bisnis yang sama. Biaya ikut dibawa (HPP rata-rata outlet asal).
     *
     * @param  array<string, mixed>  $input  to_outlet_id, note, items[ingredient_id, quantity]
     */
    public function transfer(User $user, Outlet $from, array $input): StockTransfer
    {
        $data = Validator::make($input, [
            'to_outlet_id' => ['required', 'integer', Rule::notIn([$from->id]),
                Rule::exists('outlets', 'id')->where('business_id', $from->business_id)],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct', Rule::exists('ingredients', 'id')->where('business_id', $from->business_id)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000000'],
        ], [
            'to_outlet_id.not_in' => 'Pilih outlet tujuan yang berbeda.',
            'items.required' => 'Tambahkan minimal satu bahan.',
        ], ['to_outlet_id' => 'outlet tujuan', 'items.*.quantity' => 'jumlah'])->validate();

        $to = Outlet::findOrFail($data['to_outlet_id']);

        if (! $user->canAccessOutlet($to)) {
            throw ValidationException::withMessages(['to_outlet_id' => 'Kamu tidak punya akses ke outlet tujuan.']);
        }

        return DB::transaction(function () use ($user, $from, $to, $data) {
            $transfer = StockTransfer::create([
                'business_id' => $from->business_id,
                'from_outlet_id' => $from->id,
                'to_outlet_id' => $to->id,
                'note' => $data['note'] ?? null,
                'user_id' => $user->id,
            ]);

            foreach ($data['items'] as $line) {
                $ingredient = Ingredient::findOrFail($line['ingredient_id']);
                $quantity = (float) $line['quantity'];
                $cost = $this->ledger->averageCost($from, $ingredient);

                $transfer->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => $quantity, 'unit_cost' => $cost]);
                $this->ledger->move($from, $ingredient, StockMovementType::TransferOut, -$quantity, reference: $transfer, note: 'Ke '.$to->name, user: $user);
                $this->ledger->move($to, $ingredient, StockMovementType::TransferIn, $quantity, $cost, $transfer, 'Dari '.$from->name, $user);
            }

            return $transfer;
        });
    }

    /** Mulai stok opname: catat stok sistem saat ini untuk semua bahan aktif. */
    public function startCount(User $user, Outlet $outlet, ?string $note = null): StockCount
    {
        if (StockCount::where('outlet_id', $outlet->id)->where('status', StockCount::DRAFT)->exists()) {
            throw ValidationException::withMessages(['count' => 'Masih ada stok opname yang belum diselesaikan di outlet ini.']);
        }

        return DB::transaction(function () use ($user, $outlet, $note) {
            $count = StockCount::create([
                'business_id' => $outlet->business_id,
                'outlet_id' => $outlet->id,
                'status' => StockCount::DRAFT,
                'note' => $note,
                'user_id' => $user->id,
            ]);

            $stocks = $outlet->ingredientStocks()->get()->keyBy('ingredient_id');

            foreach ($outlet->business->ingredients()->where('is_active', true)->get() as $ingredient) {
                $count->items()->create([
                    'ingredient_id' => $ingredient->id,
                    'system_quantity' => (float) ($stocks[$ingredient->id]->quantity ?? 0),
                    'unit_cost' => (float) ($stocks[$ingredient->id]->avg_cost ?? 0),
                ]);
            }

            return $count;
        });
    }

    /**
     * Selesaikan opname: selisih (fisik − sistem saat mulai) dibukukan sebagai pergerakan "opname".
     * Bahan yang tidak diisi hitungannya dianggap tidak berubah.
     *
     * @param  array<int|string, string|float|null>  $counted  stock_count_item_id → jumlah fisik
     */
    public function completeCount(User $user, StockCount $count, array $counted): StockCount
    {
        if (! $count->isDraft()) {
            throw ValidationException::withMessages(['count' => 'Stok opname ini sudah selesai.']);
        }

        foreach ($counted as $id => $value) {
            if ($value !== null && $value !== '' && (! is_numeric($value) || (float) $value < 0)) {
                throw ValidationException::withMessages(["counted.$id" => 'Jumlah fisik harus angka ≥ 0.']);
            }
        }

        return DB::transaction(function () use ($user, $count, $counted) {
            foreach ($count->items()->with('ingredient')->get() as $item) {
                $value = $counted[$item->id] ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                $difference = round((float) $value - (float) $item->system_quantity, 3);
                $item->update(['counted_quantity' => (float) $value, 'difference' => $difference]);

                if ($difference != 0.0) {
                    $this->ledger->move($count->outlet, $item->ingredient, StockMovementType::Opname, $difference,
                        $difference > 0 ? (float) $item->unit_cost : null, $count, 'Stok opname #'.$count->id, $user);
                }
            }

            $count->update(['status' => StockCount::COMPLETED, 'completed_by' => $user->id, 'completed_at' => now()]);

            return $count;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateLine(Outlet $outlet, array $input, bool $positive, bool $withCost = false): array
    {
        return Validator::make($input, [
            'ingredient_id' => ['required', 'integer', Rule::exists('ingredients', 'id')->where('business_id', $outlet->business_id)],
            'quantity' => ['required', 'numeric', $positive ? 'gt:0' : 'not_in:0', 'between:-100000000,100000000'],
            'unit_cost' => $withCost ? ['nullable', 'numeric', 'min:0', 'max:100000000'] : ['exclude'],
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['ingredient_id' => 'bahan', 'quantity' => 'jumlah', 'reason' => 'alasan', 'unit_cost' => 'nilai per satuan'])->validate();
    }
}
