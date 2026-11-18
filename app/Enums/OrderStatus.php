<?php

namespace App\Enums;

/**
 * Status pemenuhan order (Blueprint Khusus F&B bagian 7). Terpisah dari status pembayaran.
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Dikonfirmasi',
            self::Preparing => 'Disiapkan',
            self::Ready => 'Siap',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /**
     * Status open bill: pesanan sudah diterima tetapi belum selesai/lunas.
     *
     * @return list<self>
     */
    public static function openStatuses(): array
    {
        return [self::Confirmed, self::Preparing, self::Ready];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::openStatuses(), true);
    }

    /** Kelas badge di UI. */
    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'badge-gray',
            self::Confirmed, self::Preparing => 'badge-blue',
            self::Ready => 'badge-amber',
            self::Completed => 'badge-green',
            self::Cancelled => 'badge-red',
        };
    }
}
