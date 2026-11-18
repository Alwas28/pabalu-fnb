<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu pilihan di grup modifier, mis. "Less Sugar" atau "Extra Shot (+Rp5.000)". */
#[Fillable(['name', 'price', 'is_available', 'sort_order'])]
class Modifier extends Model
{
    protected $attributes = [
        'price' => 0,
        'is_available' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }
}
