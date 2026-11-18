<?php

namespace App\Support\Pos;

use App\Enums\DiscountType;
use App\Models\Outlet;

/**
 * Perhitungan total order sesuai pengaturan pajak & service charge outlet.
 *
 * Urutan: subtotal − diskon = dasar → service charge dari dasar → pajak (PB1) dari dasar + service charge.
 * Bila harga menu sudah termasuk pajak, pajak tidak ditambahkan; porsi pajaknya dihitung dari total.
 * Pembulatan ke rupiah terdekat di setiap komponen.
 */
final class OrderTotals
{
    private function __construct(
        public readonly int $subtotal,
        public readonly int $discount,
        public readonly float $serviceChargeRate,
        public readonly int $serviceCharge,
        public readonly float $taxRate,
        public readonly bool $pricesIncludeTax,
        public readonly int $tax,
        public readonly int $total,
    ) {}

    public static function calculate(Outlet $outlet, int $subtotal, ?DiscountType $discountType = null, float $discountValue = 0): self
    {
        $discount = self::discountAmount($subtotal, $discountType, $discountValue);
        $base = $subtotal - $discount;

        $serviceRate = $outlet->service_charge_enabled ? (float) $outlet->service_charge_rate : 0.0;
        $taxRate = $outlet->tax_enabled ? (float) $outlet->tax_rate : 0.0;
        $inclusive = $outlet->tax_enabled && $outlet->prices_include_tax;

        $serviceCharge = (int) round($base * $serviceRate / 100);

        if ($inclusive) {
            $total = $base + $serviceCharge;
            $tax = $total - (int) round($total * 100 / (100 + $taxRate));
        } else {
            $tax = (int) round(($base + $serviceCharge) * $taxRate / 100);
            $total = $base + $serviceCharge + $tax;
        }

        return new self($subtotal, $discount, $serviceRate, $serviceCharge, $taxRate, $inclusive, $tax, $total);
    }

    /** Diskon tidak pernah melebihi subtotal. */
    public static function discountAmount(int $subtotal, ?DiscountType $type, float $value): int
    {
        $amount = match ($type) {
            DiscountType::Amount => (int) round($value),
            DiscountType::Percent => (int) round($subtotal * min($value, 100) / 100),
            null => 0,
        };

        return max(0, min($amount, $subtotal));
    }
}
