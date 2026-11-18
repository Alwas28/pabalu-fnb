<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Keanggotaan user di sebuah bisnis beserta role & penugasan outlet.
 */
class BusinessUser extends Pivot
{
    protected $table = 'business_users';

    public $incrementing = true;

    protected $fillable = ['business_id', 'user_id', 'role', 'is_active'];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Outlet yang ditugaskan (tidak dipakai untuk role yang mencakup semua outlet).
     */
    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'business_user_outlet', 'business_user_id', 'outlet_id');
    }
}
