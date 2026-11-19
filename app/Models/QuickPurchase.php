<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Observers\QuickPurchaseAnalyticsObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Belanja bahan langsung tanpa PO & supplier. Dibatalkan = stok dikembalikan & tidak dihitung di arus kas. */
#[Fillable(['outlet_id', 'purchased_at', 'store_name', 'method', 'total', 'note', 'user_id'])]
#[ObservedBy([QuickPurchaseAnalyticsObserver::class])]
class QuickPurchase extends Model
{
    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'method' => PaymentMethod::class,
            'total' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuickPurchaseItem::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
