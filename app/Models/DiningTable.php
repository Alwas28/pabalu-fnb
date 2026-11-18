<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Meja di outlet. `occupied_at` hanya bermakna bila outlet melacak status meja
 * (outlets.table_status_enabled): terisi saat pesanan dine-in dibuat, kosong saat
 * open bill-nya lunas/dibatalkan atau dikosongkan manual oleh kasir.
 */
#[Fillable(['area', 'name', 'capacity', 'sort_order', 'is_active'])]
class DiningTable extends Model
{
    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'occupied_at' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Open bill (belum lunas, belum dibatalkan) di meja ini. */
    public function openOrders(): HasMany
    {
        return $this->orders()->open();
    }

    public function isOccupied(): bool
    {
        return $this->occupied_at !== null;
    }

    /** "Indoor · A1" */
    public function label(): string
    {
        return $this->area ? $this->area.' · '.$this->name : $this->name;
    }
}
