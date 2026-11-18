<?php

namespace App\Enums;

/** Status shift kasir (Blueprint Khusus F&B bagian 7). */
enum ShiftStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Berjalan',
            self::Closed => 'Ditutup',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Open => 'badge-green',
            self::Closed => 'badge-gray',
        };
    }
}
