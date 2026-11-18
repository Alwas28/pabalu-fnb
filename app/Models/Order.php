<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transaksi penjualan. Dibuat & diubah hanya melalui kelas di App\Support\Pos
 * (PlaceOrder, AddOrderItems, SettleOrder, CancelOrder, MoveOrderTable) agar harga,
 * pajak, pembayaran, shift dan nomor order selalu dihitung di server.
 */
class Order extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'service_charge_rate' => 'decimal:2',
            'service_charge_amount' => 'integer',
            'tax_rate' => 'decimal:2',
            'prices_include_tax' => 'boolean',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'paid_amount' => 'integer',
            'change_amount' => 'integer',
            'refunded_amount' => 'integer',
            'placed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Open bill: pesanan belum dibayar dan belum dibatalkan (status dapur apa pun). */
    public function isOpen(): bool
    {
        return $this->payment_status === PaymentStatus::Pending && $this->status !== OrderStatus::Cancelled;
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('payment_status', PaymentStatus::Pending)->where('status', '!=', OrderStatus::Cancelled);
    }

    /** Pesanan yang masih punya item aktif di dapur. */
    public function scopeInKitchen(Builder $query): void
    {
        $query->whereIn('status', OrderStatus::kitchenStatuses());
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class)->orderBy('id');
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class)->latest('id');
    }

    /** Penjualan bersih setelah pengembalian dana. */
    public function netCollected(): int
    {
        return $this->paid_amount - $this->refunded_amount;
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /** Tautan struk web publik (dipakai di QR code struk). */
    public function receiptUrl(): string
    {
        return route('receipts.show', $this->receipt_token);
    }

    /** Muat relasi yang dibutuhkan struk / detail order. */
    public function loadReceipt(): static
    {
        return $this->loadMissing(['business', 'outlet', 'cashier', 'items.modifiers', 'payments']);
    }
}
