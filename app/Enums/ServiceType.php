<?php

namespace App\Enums;

/**
 * Jenis layanan penjualan F&B. Semua tersedia di MVP; owner mengaktifkan per outlet.
 */
enum ServiceType: string
{
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::DineIn => 'Dine-in',
            self::Takeaway => 'Takeaway',
            self::Delivery => 'Delivery',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DineIn => 'Pelanggan makan di tempat',
            self::Takeaway => 'Pesanan dibawa pulang',
            self::Delivery => 'Diantar kurir / kanal delivery',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DineIn => 'fa-utensils',
            self::Takeaway => 'fa-bag-shopping',
            self::Delivery => 'fa-motorcycle',
        };
    }
}
