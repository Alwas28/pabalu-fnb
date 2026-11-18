<?php

namespace App\Enums;

/**
 * Cara pesanan diteruskan ke dapur — dipilih owner per outlet.
 */
enum KitchenMode: string
{
    /** Layar dapur (kitchen display): status per item diperbarui staf dapur. */
    case Display = 'display';

    /** Cukup cetak tiket/order ke printer dapur. */
    case Ticket = 'ticket';

    public function label(): string
    {
        return match ($this) {
            self::Display => 'Layar Dapur (Kitchen Display)',
            self::Ticket => 'Cetak Tiket',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Display => 'Pesanan tampil di layar dapur; staf dapur memperbarui status tiap item.',
            self::Ticket => 'Pesanan dicetak sebagai tiket di printer dapur, tanpa layar.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Display => 'fa-tv',
            self::Ticket => 'fa-print',
        };
    }
}
