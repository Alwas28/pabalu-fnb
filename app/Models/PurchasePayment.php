<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Observers\PurchasePaymentAnalyticsObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pembayaran tagihan supplier. */
#[ObservedBy([PurchasePaymentAnalyticsObserver::class])]
class PurchasePayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'method' => PaymentMethod::class, 'paid_at' => 'date'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
