<?php

namespace App\Enums;

/** Kanal pesanan delivery (Blueprint Khusus F&B bagian 6). */
enum DeliveryChannel: string
{
    case Direct = 'direct';
    case GoFood = 'gofood';
    case GrabFood = 'grabfood';
    case ShopeeFood = 'shopeefood';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Antar sendiri',
            self::GoFood => 'GoFood',
            self::GrabFood => 'GrabFood',
            self::ShopeeFood => 'ShopeeFood',
            self::Other => 'Aplikasi lain',
        };
    }

    /** Antar sendiri butuh kontak & alamat; kanal aplikasi cukup nomor pesanannya. */
    public function needsAddress(): bool
    {
        return $this === self::Direct;
    }
}
