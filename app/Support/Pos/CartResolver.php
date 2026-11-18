<?php

namespace App\Support\Pos;

use App\Models\MenuItem;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Mengubah isi keranjang mentah (dari kasir web / API) menjadi CartLine.
 *
 * Harga SELALU diambil dari data menu & pengaturan outlet; harga kiriman klien diabaikan.
 * Menu harus milik bisnis outlet, aktif & tersedia di outlet; varian wajib dipilih bila menu
 * memiliki varian; modifier harus berasal dari grup yang terpasang dan memenuhi batas min/maks.
 */
class CartResolver
{
    /**
     * @param  list<array{menu_item_id: int|string, variant_id?: int|string|null, modifier_ids?: list<int|string>, quantity: int|string, notes?: ?string}>  $lines
     * @return list<CartLine>
     *
     * @throws ValidationException
     */
    public function resolve(Outlet $outlet, array $lines, string $errorPrefix = 'items'): array
    {
        $menuItems = $this->loadMenuItems($outlet, array_column($lines, 'menu_item_id'));
        $errors = [];
        $resolved = [];

        foreach (array_values($lines) as $index => $line) {
            $key = "{$errorPrefix}.{$index}";
            $item = $menuItems->get((int) $line['menu_item_id']);

            if (! $item) {
                $errors[$key] = 'Menu tidak ditemukan.';

                continue;
            }

            if (! $item->isAvailableAt($outlet)) {
                $errors[$key] = "{$item->name} sedang tidak tersedia di outlet ini.";

                continue;
            }

            $variantId = $line['variant_id'] ?? null;
            $variant = $variantId ? $item->variants->firstWhere('id', (int) $variantId) : null;

            if ($variantId && ! $variant) {
                $errors[$key] = "Varian tidak valid untuk {$item->name}.";

                continue;
            }

            if (! $variant && $item->variants->isNotEmpty()) {
                $errors[$key] = "Pilih varian untuk {$item->name}.";

                continue;
            }

            $modifierIds = array_map('intval', array_unique($line['modifier_ids'] ?? []));
            $modifierError = $this->checkModifiers($item, $modifierIds);

            if ($modifierError) {
                $errors[$key] = $modifierError;

                continue;
            }

            $modifiers = $item->modifierGroups
                ->flatMap(fn ($group) => $group->modifiers->each(fn ($modifier) => $modifier->setRelation('group', $group)))
                ->filter(fn ($modifier) => in_array($modifier->id, $modifierIds, true))
                ->values();

            $unitPrice = max(0, $item->priceAt($outlet) + ($variant?->price_adjustment ?? 0)) + $modifiers->sum('price');
            $notes = trim((string) ($line['notes'] ?? ''));

            $resolved[] = new CartLine($item, $variant, $modifiers, (int) $line['quantity'], $notes !== '' ? $notes : null, $unitPrice);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * @param  list<int|string>  $ids
     * @return EloquentCollection<int, MenuItem>
     */
    private function loadMenuItems(Outlet $outlet, array $ids): EloquentCollection
    {
        return MenuItem::query()
            ->where('business_id', $outlet->business_id)
            ->whereIn('id', array_map('intval', $ids))
            ->with([
                'variants',
                'modifierGroups.modifiers',
                'outlets' => fn ($q) => $q->whereKey($outlet->id),
            ])
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  list<int>  $modifierIds
     */
    private function checkModifiers(MenuItem $item, array $modifierIds): ?string
    {
        $known = $item->modifierGroups->flatMap->modifiers->keyBy('id');

        foreach ($modifierIds as $id) {
            $modifier = $known->get($id);

            if (! $modifier) {
                return "Pilihan tambahan tidak valid untuk {$item->name}.";
            }

            if (! $modifier->is_available) {
                return "{$modifier->name} sedang tidak tersedia.";
            }
        }

        foreach ($item->modifierGroups as $group) {
            $count = $group->modifiers->whereIn('id', $modifierIds)->count();

            if ($count < $group->min_select) {
                return "{$group->name}: pilih minimal {$group->min_select}.";
            }

            if ($group->max_select !== null && $count > $group->max_select) {
                return "{$group->name}: maksimal {$group->max_select} pilihan.";
            }
        }

        return null;
    }

    /**
     * Aturan format isi keranjang (aturan bisnis diperiksa di resolve()).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.modifier_ids' => ['nullable', 'array', 'max:30'],
            'items.*.modifier_ids.*' => ['integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'items.required' => 'Keranjang masih kosong.',
            'items.min' => 'Keranjang masih kosong.',
        ];
    }

    /**
     * @param  list<CartLine>  $lines
     */
    public static function subtotal(array $lines): int
    {
        return (new Collection($lines))->sum(fn (CartLine $line) => $line->lineTotal());
    }
}
