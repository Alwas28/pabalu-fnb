<?php

namespace App\Models;

use Database\Factories\PosDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perangkat kasir (aplikasi Android) yang terdaftar di satu outlet.
 * Kodenya (K1, K2, …) menjadi bagian nomor pesanan yang dibuat saat offline,
 * mis. KDI-261119-K1-0001, sehingga tidak bentrok dengan nomor dari server.
 */
class PosDevice extends Model
{
    /** @use HasFactory<PosDeviceFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Kode perangkat berikutnya di outlet: K1, K2, … */
    public static function nextCode(Outlet $outlet): string
    {
        $numbers = self::where('outlet_id', $outlet->id)->pluck('code')
            ->map(fn (string $code) => (int) substr($code, 1));

        return 'K'.($numbers->max() + 1);
    }
}
