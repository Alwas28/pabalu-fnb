<?php

namespace App\Models;

use App\Enums\KitchenItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'voided_quantity' => 'integer',
            'line_total' => 'integer',
            'cost_amount' => 'integer',
            'kitchen_status' => KitchenItemStatus::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class)->orderBy('id');
    }

    /** Nama lengkap untuk struk, mis. "Kopi Susu (Large)". */
    public function displayName(): string
    {
        return $this->variant_name ? $this->name.' ('.$this->variant_name.')' : $this->name;
    }
}
