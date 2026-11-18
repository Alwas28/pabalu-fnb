<?php

namespace App\Http\Resources\V1;

use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Outlet
 */
class OutletResource extends JsonResource
{
    private bool $withConfig = false;

    /** Sertakan konfigurasi POS lengkap. */
    public function withConfig(): static
    {
        $this->withConfig = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'opens_at' => $this->opens_at ? substr($this->opens_at, 0, 5) : null,
            'closes_at' => $this->closes_at ? substr($this->closes_at, 0, 5) : null,
            'is_active' => $this->is_active,
        ];

        if (! $this->withConfig) {
            return $data;
        }

        $gatewayActive = $this->business->paymentGatewayActive();

        return $data + [
            'config' => [
                'service_types' => $this->service_types->map(fn (ServiceType $t) => [
                    'code' => $t->value,
                    'label' => $t->label(),
                ])->values(),
                'tables_enabled' => $this->usesTables(),
                'kitchen' => [
                    'mode' => $this->kitchen_mode->value,
                    'label' => $this->kitchen_mode->label(),
                ],
                'tax' => [
                    'enabled' => $this->tax_enabled,
                    'rate' => (float) $this->tax_rate,
                    'prices_include_tax' => $this->prices_include_tax,
                ],
                'service_charge' => [
                    'enabled' => $this->service_charge_enabled,
                    'rate' => (float) $this->service_charge_rate,
                ],
                'payment_methods' => $this->payment_methods->map(fn (PaymentMethod $m) => [
                    'code' => $m->value,
                    'label' => $m->label(),
                    // true = diverifikasi gateway; false = dikonfirmasi manual oleh kasir.
                    'via_gateway' => $gatewayActive && $m->supportsGateway(),
                ])->values(),
                'payment_gateway' => [
                    'enabled' => $gatewayActive,
                    'provider' => $gatewayActive ? $this->business->payment_gateway_provider->value : null,
                    'sandbox' => $gatewayActive ? $this->business->payment_gateway_sandbox : null,
                ],
                'currency' => $this->business->currency,
                'timezone' => $this->business->timezone,
            ],
        ];
    }
}
