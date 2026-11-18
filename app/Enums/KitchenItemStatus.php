<?php

namespace App\Enums;

/**
 * Status per item di dapur (Blueprint Khusus F&B bagian 7). Dipakai layar dapur pada Tahap 3.
 */
enum KitchenItemStatus: string
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Antre',
            self::Preparing => 'Dimasak',
            self::Ready => 'Siap',
            self::Served => 'Disajikan',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
