<?php

namespace App\Http\Resources\V1;

use App\Models\MenuItem;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Menu untuk aplikasi kasir, dengan harga & ketersediaan di outlet tertentu.
 *
 * @mixin MenuItem
 */
class MenuItemResource extends JsonResource
{
    public function __construct(MenuItem $resource, private Outlet $outlet)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $price = $this->priceAt($this->outlet);

        return [
            'id' => $this->id,
            'category_id' => $this->menu_category_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'image_url' => $this->imageUrl(),
            'price' => $price,
            'is_available' => $this->isAvailableAt($this->outlet),
            // false = menu siap saji (mis. air mineral botol): tidak masuk dapur & tidak dicetak di tiket dapur.
            'needs_kitchen' => $this->needs_kitchen,
            'variants' => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'name' => $variant->name,
                'price' => max(0, $price + $variant->price_adjustment),
            ])->values(),
            'modifier_groups' => $this->modifierGroups->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
                'min_select' => $group->min_select,
                'max_select' => $group->max_select,
                'modifiers' => $group->modifiers->map(fn ($modifier) => [
                    'id' => $modifier->id,
                    'name' => $modifier->name,
                    'price' => $modifier->price,
                    'is_available' => $modifier->is_available,
                ])->values(),
            ])->values(),
        ];
    }
}
