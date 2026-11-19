<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kategori pengeluaran milik satu bisnis: salinan daftar bawaan super admin (template_id) atau dibuat owner. */
#[Fillable(['name', 'template_id'])]
class ExpenseCategory extends Model
{
    /**
     * Pastikan bisnis punya semua kategori bawaan yang aktif. Kategori owner dengan nama sama
     * ditautkan ke daftar bawaan, bukan diduplikasi.
     */
    public static function syncTemplatesFor(Business $business): void
    {
        $existing = $business->expenseCategories()->get(['id', 'name', 'template_id']);
        $linkedTemplateIds = $existing->pluck('template_id')->filter()->all();

        $missing = ExpenseCategoryTemplate::where('is_active', true)->whereNotIn('id', $linkedTemplateIds)->get();

        foreach ($missing as $template) {
            $sameName = $existing->first(fn (self $category) => mb_strtolower($category->name) === mb_strtolower($template->name));

            if ($sameName === null) {
                $business->expenseCategories()->create(['name' => $template->name, 'template_id' => $template->id]);
            } elseif ($sameName->template_id === null) {
                $sameName->update(['template_id' => $template->id]);
            }
        }
    }

    /** Kategori yang boleh dipilih saat mencatat: buatan owner, atau bawaan yang masih aktif. */
    public function scopeSelectable(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('template_id')
            ->orWhereHas('template', fn (Builder $query) => $query->where('is_active', true)));
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategoryTemplate::class, 'template_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
