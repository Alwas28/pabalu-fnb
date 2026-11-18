<?php

namespace App\Enums;

/**
 * Status per item di dapur (Blueprint Khusus F&B bagian 7).
 * Antre & Disajikan selalu dipakai; Dimasak & Siap opsional per outlet.
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

    /** Label tombol untuk memajukan item KE status ini. */
    public function actionLabel(): string
    {
        return match ($this) {
            self::Preparing => 'Mulai masak',
            self::Ready => 'Siap',
            self::Served => 'Sajikan',
            default => $this->label(),
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Queued => 'badge-gray',
            self::Preparing => 'badge-blue',
            self::Ready => 'badge-amber',
            self::Served => 'badge-green',
            self::Cancelled => 'badge-red',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Preparing, self::Ready], true);
    }
}
