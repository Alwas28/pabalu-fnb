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

    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class)->orderBy('sort_order')->orderBy('name');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function modifierGroups(): HasMany
    {
        return $this->hasMany(ModifierGroup::class)->orderBy('sort_order')->orderBy('name');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class)->orderBy('name');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class)->orderBy('name');
    }

    public function expenseCategories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class)->orderBy('name');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
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
