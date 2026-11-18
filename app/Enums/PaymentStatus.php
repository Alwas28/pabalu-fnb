<?php

namespace App\Enums;

/**
 * Status pembayaran order (Blueprint Khusus F&B bagian 7).
 * Baris pembayaran (order_payments) memakai Pending / Paid / Failed / Refunded.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Belum dibayar',
            self::PartiallyPaid => 'Dibayar sebagian',
            self::Paid => 'Lunas',
            self::Failed => 'Gagal',
            self::Refunded => 'Dikembalikan',
            self::PartiallyRefunded => 'Dikembalikan sebagian',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Paid => 'badge-green',
            self::PartiallyPaid, self::PartiallyRefunded => 'badge-amber',
            self::Failed => 'badge-red',
            self::Pending, self::Refunded => 'badge-gray',
        };
    }
}
