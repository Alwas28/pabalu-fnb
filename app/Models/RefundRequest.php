<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentMethod;
use App\Enums\RefundType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pengajuan void / refund yang menunggu keputusan owner. */
class RefundRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => RefundType::class,
            'status' => ApprovalStatus::class,
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class);
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }
}
