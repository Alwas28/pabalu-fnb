<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori pengeluaran bawaan yang dikelola super admin. Setiap bisnis mendapat salinannya
 * (expense_categories.template_id) saat membuka menu Pengeluaran; owner tetap boleh menambah kategori sendiri.
 */
#[Fillable(['name', 'is_active'])]
class ExpenseCategoryTemplate extends Model
{
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class, 'template_id');
    }
}
