<?php

namespace App\Enums;

enum PurchaseStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Partial = 'partial';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ordered => 'Dipesan',
            self::Partial => 'Diterima sebagian',
            self::Received => 'Diterima',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'badge-gray',
            self::Ordered => 'badge-blue',
            self::Partial => 'badge-amber',
            self::Received => 'badge-green',
            self::Cancelled => 'badge-red',
        };
    }
}
