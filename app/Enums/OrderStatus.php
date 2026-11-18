<?php

namespace App\Enums;

/**
 * Status pemenuhan order (Blueprint Khusus F&B bagian 7). Terpisah dari status pembayaran.
 *
 * Outlet dengan layar dapur: Dikonfirmasi → Disiapkan → Siap → Disajikan (semua item tersaji, belum lunas)
 * → Selesai (tersaji & lunas). Outlet tanpa layar dapur: item dianggap langsung tersaji.
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Status saat pesanan masih diproses dapur.
     *
     * @return list<self>
     */
    public static function kitchenStatuses(): array
    {
        return [self::Confirmed, self::Preparing, self::Ready];
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Dikonfirmasi',
            self::Preparing => 'Disiapkan',
            self::Ready => 'Siap',
            self::Served => 'Disajikan',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** Kelas badge di UI. */
    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'badge-gray',
            self::Confirmed, self::Preparing => 'badge-blue',
            self::Ready, self::Served => 'badge-amber',
            self::Completed => 'badge-green',
            self::Cancelled => 'badge-red',
        };
    }
}
