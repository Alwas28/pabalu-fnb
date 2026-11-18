<?php

namespace App\Enums;

enum RefundType: string
{
    /** Batalkan seluruh transaksi yang sudah dibayar; semua pembayaran dikembalikan. */
    case Void = 'void';

    /** Kembalikan sebagian atau seluruh uang; transaksi tetap tercatat. */
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Void => 'Void',
            self::Refund => 'Refund',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Void => 'Batalkan seluruh transaksi, uang dikembalikan sesuai pembayaran asal',
            self::Refund => 'Kembalikan sebagian / seluruh nominal, transaksi tetap tercatat',
        };
    }
}
