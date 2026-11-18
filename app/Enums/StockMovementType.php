<?php

namespace App\Enums;

/** Jenis pergerakan stok (Blueprint Khusus F&B bagian 8). */
enum StockMovementType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case PurchaseReturn = 'purchase_return';
    case RecipeUsage = 'recipe_usage';
    case RecipeReturn = 'recipe_return';
    case Waste = 'waste';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Adjustment = 'adjustment';
    case Opname = 'opname';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseReceipt => 'Penerimaan pembelian',
            self::PurchaseReturn => 'Retur pembelian',
            self::RecipeUsage => 'Pemakaian resep',
            self::RecipeReturn => 'Pengembalian (void)',
            self::Waste => 'Waste / rusak',
            self::TransferOut => 'Transfer keluar',
            self::TransferIn => 'Transfer masuk',
            self::Adjustment => 'Penyesuaian',
            self::Opname => 'Stok opname',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::PurchaseReceipt, self::TransferIn, self::RecipeReturn => 'badge-green',
            self::RecipeUsage => 'badge-blue',
            self::Waste, self::PurchaseReturn => 'badge-red',
            self::TransferOut => 'badge-amber',
            self::Adjustment, self::Opname => 'badge-gray',
        };
    }
}
