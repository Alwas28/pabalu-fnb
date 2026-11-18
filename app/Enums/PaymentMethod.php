<?php

namespace App\Enums;

/**
 * Metode pembayaran yang dapat diaktifkan per outlet.
 * Tanpa payment gateway, metode non-tunai dicatat manual oleh kasir.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case BankTransfer = 'bank_transfer';
    case EWallet = 'e_wallet';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Qris => 'QRIS',
            self::DebitCard => 'Kartu Debit',
            self::CreditCard => 'Kartu Kredit',
            self::BankTransfer => 'Transfer Bank',
            self::EWallet => 'E-Wallet',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cash => 'fa-money-bill-wave',
            self::Qris => 'fa-qrcode',
            self::DebitCard => 'fa-credit-card',
            self::CreditCard => 'fa-credit-card',
            self::BankTransfer => 'fa-building-columns',
            self::EWallet => 'fa-wallet',
        };
    }

    /**
     * Metode yang bisa diproses otomatis oleh payment gateway (bila diaktifkan).
     */
    public function supportsGateway(): bool
    {
        return $this !== self::Cash;
    }
}
