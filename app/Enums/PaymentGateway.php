<?php

namespace App\Enums;

/**
 * Penyedia payment gateway (opsional, diatur per bisnis).
 * Integrasi API masing-masing provider dibangun terpisah.
 */
enum PaymentGateway: string
{
    case Midtrans = 'midtrans';
    case Xendit = 'xendit';
    case Duitku = 'duitku';

    public function label(): string
    {
        return match ($this) {
            self::Midtrans => 'Midtrans',
            self::Xendit => 'Xendit',
            self::Duitku => 'Duitku',
        };
    }
}
