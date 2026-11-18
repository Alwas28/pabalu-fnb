<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu pilihan di grup modifier, mis. "Less Sugar" atau "Extra Shot (+Rp5.000)". */
#[Fillable(['name', 'price', 'is_available', 'sort_order'])]
class Modifier extends Model
{
    use Auditable;

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

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    /** @return list<string> */
    public function auditedAttributes(): array
    {
        return ['name', 'price', 'is_available'];
    }

    public function auditBusinessId(): ?int
    {
        return $this->group?->business_id;
    }

    public function auditLabel(): string
    {
        return ($this->group?->name ?? 'Modifier').' · '.$this->name;
    }
}
