<?php

namespace App\Support\Reports\Excel;

use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Support\Reports\Period;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan stok Excel: saldo & nilai persediaan saat ini, mutasi stok periode (saldo awal → masuk/keluar
 * per jenis → saldo akhir), dan rincian setiap pergerakan. Sumber: ledger stock_movements.
 */
class StockWorkbook
{
    /** Urutan & pengelompokan kolom mutasi. */
    private const MOVEMENT_COLUMNS = [
        StockMovementType::PurchaseReceipt,
        StockMovementType::QuickPurchase,
        StockMovementType::TransferIn,
        StockMovementType::RecipeReturn,
        StockMovementType::RecipeUsage,
        StockMovementType::Waste,
        StockMovementType::TransferOut,
        StockMovementType::PurchaseReturn,
        StockMovementType::Adjustment,
        StockMovementType::Opname,
    ];

    /** @param  list<int>  $outletIds */
    public function __construct(
        private string $businessName,
        private array $outletIds,
        private string $outletLabel,
        private Period $period,
    ) {}

    public function build(): WorkbookWriter
    {
        $excel = new WorkbookWriter('Laporan Stok', $this->businessName);
        $info = [
            $this->businessName.' · '.$this->outletLabel,
            'Periode mutasi: '.$this->period->label(),
            'Dibuat: '.now($this->period->timezone)->translatedFormat('d M Y H:i'),
        ];

        $this->balances($excel, $info);
        $this->movements($excel, $info);
        $this->details($excel, $info);

        return $excel;
    }

    public function download(): StreamedResponse
    {
        $name = 'laporan-stok-'.$this->period->from->toDateString().'_'.$this->period->to->toDateString().'.xlsx';

        return $this->build()->download($name);
    }

    /** @param  list<string>  $info */
    private function balances(WorkbookWriter $excel, array $info): void
    {
        $sheet = $excel->sheet('Saldo Persediaan');
        $row = $excel->title($sheet, 'Saldo & Nilai Persediaan (saat ini)', $info);

        $stocks = IngredientStock::whereIn('outlet_id', $this->outletIds)->with(['ingredient', 'outlet'])->get()
            ->sortBy(fn (IngredientStock $s) => $s->outlet->name.' '.$s->ingredient->name)->values();

        $rows = $stocks->map(function (IngredientStock $s) {
            $quantity = (float) $s->quantity;
            $minimum = (float) $s->ingredient->min_stock;

            return [
                $s->outlet->name,
                $s->ingredient->name,
                $s->ingredient->sku,
                $quantity,
                $s->ingredient->base_unit->value,
                $minimum,
                $quantity < 0 ? 'Minus' : ($quantity <= $minimum ? 'Menipis' : 'Aman'),
                round((float) $s->avg_cost, 2),
                $s->value(),
            ];
        });

        $excel->table($sheet, $row, [
            ['Outlet', 'text'], ['Bahan', 'text'], ['SKU', 'text'], ['Stok', 'number'], ['Satuan', 'text'], ['Minimum', 'number'],
            ['Status', 'text'], ['HPP rata-rata / satuan', 'money'], ['Nilai persediaan', 'money'],
        ], $rows, ['Total nilai persediaan', '', '', '', '', '', '', '', $rows->sum(8)], freeze: true);
    }

    /** @param  list<string>  $info */
    private function movements(WorkbookWriter $excel, array $info): void
    {
        $sheet = $excel->sheet('Mutasi Stok');
        $row = $excel->title($sheet, 'Mutasi Stok', $info);
        [$start, $end] = $this->period->utcRange();

        $opening = StockMovement::whereIn('outlet_id', $this->outletIds)->where('created_at', '<', $start)
            ->selectRaw('outlet_id, ingredient_id, sum(quantity) as qty')->groupBy('outlet_id', 'ingredient_id')->get()
            ->mapWithKeys(fn ($r) => [$r->outlet_id.'-'.$r->ingredient_id => (float) $r->qty]);

        $changes = StockMovement::whereIn('outlet_id', $this->outletIds)->whereBetween('created_at', [$start, $end])
            ->selectRaw('outlet_id, ingredient_id, type, sum(quantity) as qty')->groupBy('outlet_id', 'ingredient_id', 'type')->get()
            ->groupBy(fn ($r) => $r->outlet_id.'-'.$r->ingredient_id);

        $keys = $opening->keys()->merge($changes->keys())->unique();
        $outlets = Outlet::whereIn('id', $this->outletIds)->pluck('name', 'id');
        $ingredients = Ingredient::whereIn('id', $keys->map(fn ($k) => (int) explode('-', $k)[1]))->get()->keyBy('id');

        $rows = $keys->map(function (string $key) use ($opening, $changes, $outlets, $ingredients) {
            [$outletId, $ingredientId] = array_map('intval', explode('-', $key));
            $ingredient = $ingredients->get($ingredientId);
            $byType = ($changes->get($key) ?? collect())->mapWithKeys(fn ($r) => [$r->type instanceof StockMovementType ? $r->type->value : $r->type => (float) $r->qty]);
            $start = $opening->get($key, 0.0);

            $values = [];
            foreach (self::MOVEMENT_COLUMNS as $type) {
                $values[] = round($byType->get($type->value, 0.0), 3);
            }

            return [
                $outlets->get($outletId, '—'),
                $ingredient?->name ?? '—',
                $ingredient?->base_unit->value,
                round($start, 3),
                ...$values,
                round($start + array_sum($values), 3),
            ];
        })
            // Lewati bahan tanpa saldo & tanpa pergerakan di periode ini.
            ->reject(fn (array $r) => $r[3] == 0 && collect(array_slice($r, 4))->every(fn ($v) => $v == 0))
            ->sortBy(fn (array $r) => $r[0].' '.$r[1])->values();

        $columns = [['Outlet', 'text'], ['Bahan', 'text'], ['Satuan', 'text'], ['Saldo awal', 'number']];
        foreach (self::MOVEMENT_COLUMNS as $type) {
            $columns[] = [$type->label(), 'number'];
        }
        $columns[] = ['Saldo akhir', 'number'];

        $row = $excel->table($sheet, $row, $columns, $rows, freeze: true);
        $excel->note($sheet, $row, 'Angka positif = stok masuk, negatif = stok keluar. Saldo akhir = saldo awal + seluruh pergerakan dalam periode. Jumlah dalam satuan dasar bahan.');
    }

    /** @param  list<string>  $info */
    private function details(WorkbookWriter $excel, array $info): void
    {
        $sheet = $excel->sheet('Rincian Mutasi');
        $row = $excel->title($sheet, 'Rincian Pergerakan Stok', $info);

        $movements = StockMovement::whereIn('outlet_id', $this->outletIds)->whereBetween('created_at', $this->period->utcRange())
            ->with(['outlet', 'ingredient', 'user'])->orderBy('created_at')->orderBy('id')
            ->limit(WorkbookWriter::MAX_DETAIL_ROWS)->get();

        $rows = $movements->map(fn (StockMovement $m) => [
            $m->created_at->timezone($this->period->timezone)->format('d/m/Y H:i'),
            $m->outlet?->name,
            $m->ingredient?->name,
            $m->type->label(),
            (float) $m->quantity,
            $m->ingredient?->base_unit->value,
            round((float) $m->unit_cost, 2),
            (int) round((float) $m->quantity * (float) $m->unit_cost),
            (float) $m->balance_after,
            $m->note,
            $m->user?->name,
        ]);

        $row = $excel->table($sheet, $row, [
            ['Waktu', 'text'], ['Outlet', 'text'], ['Bahan', 'text'], ['Jenis', 'text'], ['Jumlah', 'number'], ['Satuan', 'text'],
            ['Nilai / satuan', 'money'], ['Nilai', 'money'], ['Saldo setelah', 'number'], ['Catatan', 'text'], ['Oleh', 'text'],
        ], $rows, freeze: true);

        if ($movements->count() >= WorkbookWriter::MAX_DETAIL_ROWS) {
            $excel->note($sheet, $row, 'Dibatasi '.number_format(WorkbookWriter::MAX_DETAIL_ROWS, 0, ',', '.').' pergerakan pertama. Persempit periode untuk melihat semuanya.');
        }
    }
}
