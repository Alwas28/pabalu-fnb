<?php

namespace App\Enums;

/** Satuan dasar bahan baku. Satuan beli (kg, liter, dus, …) dikonversi ke satuan ini. */
enum StockUnit: string
{
    case Gram = 'g';
    case Milliliter = 'ml';
    case Piece = 'pcs';

    public function label(): string
    {
        return match ($this) {
            self::Gram => 'gram (g)',
            self::Milliliter => 'mililiter (ml)',
            self::Piece => 'pcs',
        };
    }

    /** Satuan beli umum beserta isinya dalam satuan dasar. */
    public function commonPurchaseUnits(): array
    {
        return match ($this) {
            self::Gram => ['kg' => 1000],
            self::Milliliter => ['liter' => 1000],
            self::Piece => [],
        };
    }
}
