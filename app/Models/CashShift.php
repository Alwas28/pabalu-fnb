<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use App\Observers\CashShiftAnalyticsObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shift kasir di satu outlet. Dibuka & ditutup lewat App\Support\Pos\ShiftManager.
 * Satu kasir hanya boleh memiliki satu shift berjalan per outlet.
 */
#[ObservedBy([CashShiftAnalyticsObserver::class])]
class CashShift extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'opening_cash' => 'integer',
            'expected_cash' => 'integer',
            'actual_cash' => 'integer',
            'cash_difference' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    /** Shift yang sedang berjalan milik kasir di outlet tersebut. */
    public static function currentFor(User $user, Outlet $outlet): ?self
    {
        return self::query()
            ->where('outlet_id', $outlet->id)
            ->where('user_id', $user->id)
            ->where('status', ShiftStatus::Open)
            ->latest('id')
            ->first();
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ShiftStatus::Open);
    }

    public function isOpen(): bool
    {
        return $this->status === ShiftStatus::Open;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    /** Order yang dibuat selama shift ini. */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
