<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

#[Fillable(['name', 'phone', 'email', 'birthday', 'notes'])]
class Customer extends Model
{
    protected function casts(): array
    {
        return ['birthday' => 'date'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Order yang dihitung sebagai kunjungan (tidak dibatalkan). */
    public function validOrders(): HasMany
    {
        return $this->orders()->where('status', '!=', OrderStatus::Cancelled);
    }

    /**
     * Cari berdasarkan nama, atau nomor HP bila kata kunci mengandung angka.
     *
     * @param  Builder<self>|HasMany<self, Business>  $query
     */
    public static function applySearch($query, string $term)
    {
        $phone = self::normalizePhone($term);

        return $query->where(fn ($q) => $q
            ->where('name', 'like', '%'.$term.'%')
            ->when($phone !== null && strlen($phone) >= 3, fn ($q) => $q->orWhere('phone', 'like', '%'.$phone.'%')));
    }

    /** Nomor HP dinormalisasi: hanya angka, awalan 62 → 0. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        return str_starts_with($digits, '62') ? '0'.substr($digits, 2) : $digits;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(int $businessId, ?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('customers', 'phone')->where('business_id', $businessId)->ignore($ignoreId)],
            'email' => ['nullable', 'email', 'max:255'],
            'birthday' => ['nullable', 'date', 'before:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
