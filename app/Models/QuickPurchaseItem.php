<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu bahan dalam Belanja Cepat. Jumlah dalam satuan dasar bahan; unit_cost = total_price / base_quantity. */
#[Fillable(['ingredient_id', 'base_quantity', 'total_price', 'unit_cost'])]
class QuickPurchaseItem extends Model
{
    protected function casts(): array
    {
        return [
            'base_quantity' => 'decimal:3',
            'total_price' => 'integer',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
