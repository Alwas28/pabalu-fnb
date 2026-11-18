<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase order ke supplier. Diubah hanya lewat App\Support\Purchasing\PurchaseService.
 * Tagihan = nilai barang diterima − nilai retur.
 */
class PurchaseOrder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'due_date' => 'date',
            'total' => 'integer',
            'received_total' => 'integer',
            'returned_total' => 'integer',
            'paid_amount' => 'integer',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class)->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class)->orderBy('id');
    }

    /** Tagihan ke supplier (barang diterima − retur). */
    public function payable(): int
    {
        return max(0, $this->received_total - $this->returned_total);
    }

    public function outstanding(): int
    {
        return max(0, $this->payable() - $this->paid_amount);
    }

    /** unpaid / partial / paid / none (belum ada barang diterima) */
    public function billStatus(): string
    {
        return match (true) {
            $this->payable() === 0 => 'none',
            $this->outstanding() === 0 => 'paid',
            $this->paid_amount > 0 => 'partial',
            default => 'unpaid',
        };
    }

    public function isEditable(): bool
    {
        return $this->status === PurchaseStatus::Draft;
    }

    public function canReceive(): bool
    {
        return in_array($this->status, [PurchaseStatus::Ordered, PurchaseStatus::Partial], true);
    }
}
