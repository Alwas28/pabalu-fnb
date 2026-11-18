<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class ExpenseCategory extends Model
{
    /** Kategori bawaan untuk bisnis baru. */
    public const DEFAULTS = ['Gaji & Upah', 'Sewa Tempat', 'Listrik & Air', 'Gas & Bahan Bakar', 'Pemasaran', 'Perawatan & Perbaikan', 'Lain-lain'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
