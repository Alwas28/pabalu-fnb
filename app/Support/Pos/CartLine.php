<?php

namespace App\Support\Pos;

use App\Models\MenuItem;
use App\Models\MenuVariant;
use App\Models\Modifier;
use Illuminate\Support\Collection;

/**
 * Satu baris keranjang yang sudah divalidasi & diberi harga dari data server.
 */
final class CartLine
{
    /**
     * @param  Collection<int, Modifier>  $modifiers  (relasi `group` dimuat)
     */
    public function __construct(
        public readonly MenuItem $menuItem,
        public readonly ?MenuVariant $variant,
        public readonly Collection $modifiers,
        public readonly int $quantity,
        public readonly ?string $notes,
        public readonly int $unitPrice,
    ) {}

    public function lineTotal(): int
    {
        return $this->unitPrice * $this->quantity;
    }

    public function displayName(): string
    {
        return $this->variant ? $this->menuItem->name.' ('.$this->variant->name.')' : $this->menuItem->name;
    }

    /** "Less Sugar, Extra Shot" */
    public function modifierSummary(): string
    {
        return $this->modifiers->pluck('name')->join(', ');
    }
}
