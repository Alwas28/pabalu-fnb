<?php

namespace App\Models;

use App\Enums\PaymentGateway;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'phone', 'email', 'address', 'timezone', 'currency',
    'payment_gateway_enabled', 'payment_gateway_provider', 'payment_gateway_sandbox', 'payment_gateway_credentials',
])]
#[Hidden(['payment_gateway_credentials'])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payment_gateway_enabled' => 'boolean',
            'payment_gateway_provider' => PaymentGateway::class,
            'payment_gateway_sandbox' => 'boolean',
            'payment_gateway_credentials' => 'encrypted:array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class)->orderBy('name');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_users')
            ->using(BusinessUser::class)
            ->withPivot(['id', 'role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * Gateway hanya dianggap aktif bila dinyalakan DAN provider dipilih.
     */
    public function paymentGatewayActive(): bool
    {
        return $this->payment_gateway_enabled && $this->payment_gateway_provider !== null;
    }
}
