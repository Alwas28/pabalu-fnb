<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Jejak audit perubahan data sensitif (Blueprint Khusus F&B bagian 14):
 * harga & ketersediaan menu, resep, bahan, pengaturan outlet, hak akses & role karyawan.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Catat satu kejadian. Pelaku = pengguna yang login (null bila dari sistem / seeder).
     *
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $changes
     */
    public static function record(int $businessId, ?Model $subject, string $event, string $label, ?array $changes = null, ?string $note = null): ?self
    {
        if ($changes !== null && $changes === []) {
            return null;
        }

        return self::create([
            'business_id' => $businessId,
            'user_id' => auth()->id(),
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'event' => $event,
            'subject' => mb_substr($label, 0, 160),
            'changes' => $changes,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /** Jenis objek yang mudah dibaca. */
    public function typeLabel(): string
    {
        return match ($this->auditable_type) {
            MenuItem::class => 'Menu',
            MenuVariant::class => 'Varian',
            Modifier::class => 'Modifier',
            Ingredient::class => 'Bahan baku',
            Outlet::class => 'Outlet',
            BusinessUser::class => 'Karyawan',
            Business::class => 'Bisnis',
            default => 'Lainnya',
        };
    }

    public function eventLabel(): string
    {
        return match ($this->event) {
            'created' => 'Dibuat',
            'updated' => 'Diubah',
            'deleted' => 'Dihapus',
            'recipe' => 'Resep diubah',
            'outlet_price' => 'Harga/ketersediaan outlet',
            'permissions' => 'Hak akses role',
            default => ucfirst($this->event),
        };
    }
}
