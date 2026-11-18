<?php

namespace App\Enums;

enum DiscountType: string
{
    /** Potongan nominal rupiah. */
    case Amount = 'amount';

    /** Potongan persentase dari subtotal. */
    case Percent = 'percent';

    public function label(): string
    {
        return match ($this) {
            self::Amount => 'Nominal (Rp)',
            self::Percent => 'Persen (%)',
        };
    }
}
