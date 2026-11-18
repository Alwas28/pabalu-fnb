<?php

namespace App\Models;

use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Menu yang dijual. Harga dasar berlaku di semua outlet kecuali ditimpa
 * lewat pengaturan per outlet (menu_item_outlet).
 */
#[Fillable(['menu_category_id', 'name', 'sku', 'description', 'image_path', 'price', 'sort_order', 'is_active'])]
class MenuItem extends Model
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory;

    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MenuVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class)
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /** Pengaturan harga & ketersediaan per outlet. */
    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class)
            ->withPivot(['price', 'is_available'])
            ->withTimestamps();
    }

    /** Harga menu di outlet: harga khusus outlet bila ada, jika tidak harga dasar. */
    public function priceAt(Outlet $outlet): int
    {
        $price = $this->outletSetting($outlet)?->pivot->price;

        return $price === null ? $this->price : (int) $price;
    }

    /** Menu aktif dan tidak ditandai habis / tidak dijual di outlet. */
    public function isAvailableAt(Outlet $outlet): bool
    {
        return $this->is_active && (bool) ($this->outletSetting($outlet)?->pivot->is_available ?? true);
    }

    private function outletSetting(Outlet $outlet): ?Outlet
    {
        return $this->outlets->firstWhere('id', $outlet->id);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
