<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Varian menu (mis. ukuran). Harga = harga menu di outlet + price_adjustment.
 */
#[Fillable(['name', 'price_adjustment', 'sort_order'])]
class MenuVariant extends Model
{
    protected $attributes = [
        'price_adjustment' => 0,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price_adjustment' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}
