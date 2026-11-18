<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Saldo stok bahan di satu outlet + HPP rata-rata tertimbang. Diubah hanya lewat StockLedger. */
class IngredientStock extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'avg_cost' => 'decimal:4',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** Nilai persediaan (rupiah). */
    public function value(): int
    {
        return (int) round(max(0, (float) $this->quantity) * (float) $this->avg_cost);
    }
}
