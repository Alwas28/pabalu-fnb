<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Observers\OrderRefundAnalyticsObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uang yang dikembalikan ke pelanggan. Tunai dengan cash_shift_id mengurangi kas laci shift itu. */
#[ObservedBy([OrderRefundAnalyticsObserver::class])]
class OrderRefund extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class, 'refund_request_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
