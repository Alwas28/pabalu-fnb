<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_size' => 'decimal:3',
            'unit_price' => 'integer',
            'line_total' => 'integer',
            'received_base_quantity' => 'decimal:3',
            'returned_base_quantity' => 'decimal:3',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** Jumlah dipesan dalam satuan dasar. */
    public function orderedBase(): float
    {
        return round((float) $this->quantity * (float) $this->unit_size, 3);
    }

    public function remainingBase(): float
    {
        return max(0, round($this->orderedBase() - (float) $this->received_base_quantity, 3));
    }

    /** Harga per satuan dasar. */
    public function unitCost(): float
    {
        return (float) $this->unit_size > 0 ? $this->unit_price / (float) $this->unit_size : 0;
    }

    public function returnableBase(): float
    {
        return max(0, round((float) $this->received_base_quantity - (float) $this->returned_base_quantity, 3));
    }
}
