<?php

namespace App\Models;

use Database\Factories\ModifierGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kelompok pilihan tambahan (mis. "Level Gula", "Topping") yang bisa dipasang ke banyak menu.
 * min_select = 0 berarti opsional; max_select = null berarti tanpa batas.
 */
#[Fillable(['name', 'min_select', 'max_select', 'sort_order'])]
class ModifierGroup extends Model
{
    /** @use HasFactory<ModifierGroupFactory> */
    use HasFactory;

    protected $attributes = [
        'min_select' => 0,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order')->orderBy('id');
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class);
    }

    /** Ringkasan aturan pilihan, mis. "Wajib pilih 1" atau "Opsional, maks. 3". */
    public function ruleLabel(): string
    {
        if ($this->min_select > 0 && $this->max_select === $this->min_select) {
            return 'Wajib pilih '.$this->min_select;
        }

        $parts = [$this->min_select > 0 ? 'Wajib min. '.$this->min_select : 'Opsional'];

        if ($this->max_select !== null) {
            $parts[] = 'maks. '.$this->max_select;
        }

        return implode(', ', $parts);
    }
}
