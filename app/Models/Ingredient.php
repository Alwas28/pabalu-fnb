<?php

namespace App\Models;

use App\Enums\StockUnit;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bahan baku. Jumlah selalu dalam satuan dasar (g / ml / pcs). */
#[Fillable(['name', 'sku', 'base_unit', 'purchase_unit', 'purchase_unit_size', 'min_stock', 'is_active'])]
class Ingredient extends Model
{
    use Auditable;

    protected $attributes = [
        'min_stock' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'base_unit' => StockUnit::class,
            'purchase_unit_size' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(IngredientStock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** "1 dus = 24 pcs" */
    public function purchaseUnitLabel(): ?string
    {
        if (! $this->purchase_unit || ! $this->purchase_unit_size) {
            return null;
        }

        return '1 '.$this->purchase_unit.' = '.self::qty($this->purchase_unit_size).' '.$this->base_unit->value;
    }

    /** Format jumlah tanpa nol berlebih: 1500.000 → "1.500", 2.5 → "2,5". */
    public static function qty(float|string|null $value): string
    {
        $value = (float) $value;
        $formatted = number_format($value, 3, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',');
    }

    /** @return list<string> */
    public function auditedAttributes(): array
    {
        return ['name', 'base_unit', 'purchase_unit', 'purchase_unit_size', 'min_stock', 'is_active'];
    }

    public function auditBusinessId(): ?int
    {
        return $this->business_id;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
