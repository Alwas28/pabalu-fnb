<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Varian menu (mis. ukuran). Harga = harga menu di outlet + price_adjustment.
 */
#[Fillable(['name', 'price_adjustment', 'sort_order'])]
class MenuVariant extends Model
{
    use Auditable;

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

    /** @return list<string> */
    public function auditedAttributes(): array
    {
        return ['name', 'price_adjustment'];
    }

    public function auditBusinessId(): ?int
    {
        return $this->menuItem?->business_id;
    }

    public function auditLabel(): string
    {
        return ($this->menuItem?->name ?? 'Menu').' · varian '.$this->name;
    }
}
