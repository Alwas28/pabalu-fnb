<?php

namespace App\Models;

use App\Enums\KitchenMode;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use Database\Factories\OutletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'name', 'phone', 'address', 'opens_at', 'closes_at', 'is_active',
    'service_types', 'tables_enabled', 'kitchen_mode',
    'tax_enabled', 'tax_rate', 'service_charge_enabled', 'service_charge_rate', 'prices_include_tax',
    'payment_methods', 'receipt_paper_width', 'receipt_footer',
])]
class Outlet extends Model
{
    /** @use HasFactory<OutletFactory> */
    use HasFactory;

    /**
     * Nilai default pengaturan outlet baru.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'service_types' => '["dine_in","takeaway"]',
        'tables_enabled' => false,
        'kitchen_mode' => 'ticket',
        'tax_enabled' => false,
        'tax_rate' => 0,
        'service_charge_enabled' => false,
        'service_charge_rate' => 0,
        'prices_include_tax' => false,
        'payment_methods' => '["cash","qris"]',
        'receipt_paper_width' => 58,
    ];

    /** Lebar kertas struk thermal yang didukung (mm). */
    public const RECEIPT_PAPER_WIDTHS = [58, 80];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'service_types' => AsEnumCollection::of(ServiceType::class),
            'tables_enabled' => 'boolean',
            'kitchen_mode' => KitchenMode::class,
            'tax_enabled' => 'boolean',
            'tax_rate' => 'decimal:2',
            'service_charge_enabled' => 'boolean',
            'service_charge_rate' => 'decimal:2',
            'prices_include_tax' => 'boolean',
            'payment_methods' => AsEnumCollection::of(PaymentMethod::class),
            'receipt_paper_width' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(BusinessUser::class, 'business_user_outlet');
    }

    public function offers(ServiceType $type): bool
    {
        return $this->service_types->contains($type);
    }

    public function accepts(PaymentMethod $method): bool
    {
        return $this->payment_methods->contains($method);
    }

    /**
     * Meja hanya relevan bila dine-in aktif.
     */
    public function usesTables(): bool
    {
        return $this->tables_enabled && $this->offers(ServiceType::DineIn);
    }

    public function usesKitchenDisplay(): bool
    {
        return $this->kitchen_mode === KitchenMode::Display;
    }
}
