<?php

namespace App\Enums;

/** Kas masuk / keluar laci di luar penjualan (mis. tambahan uang kecil, setor ke owner, beli es batu). */
enum CashMovementType: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Kas masuk',
            self::Out => 'Kas keluar',
        };
    }
}
