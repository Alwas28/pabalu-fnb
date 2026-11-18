<?php

namespace App\Support;

/**
 * Nominal uang disimpan sebagai bilangan bulat rupiah.
 */
class Money
{
    /** 25000 → "Rp 25.000"; -5000 → "-Rp 5.000". */
    public static function format(int $amount): string
    {
        return ($amount < 0 ? '-' : '').'Rp '.number_format(abs($amount), 0, ',', '.');
    }

    /** 25000 → "25.000" (tanpa simbol, untuk kolom struk sempit). */
    public static function plain(int $amount): string
    {
        return ($amount < 0 ? '-' : '').number_format(abs($amount), 0, ',', '.');
    }

    /**
     * Ubah input pengguna ("25.000", "25000", "Rp 25.000") menjadi rupiah.
     * Mengembalikan null bila input kosong / tidak berupa angka.
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : (int) $digits;
    }
}
