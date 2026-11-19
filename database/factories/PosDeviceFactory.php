<?php

namespace Database\Factories;

use App\Models\Outlet;
use App\Models\PosDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosDevice>
 */
class PosDeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'business_id' => fn (array $attributes) => Outlet::find($attributes['outlet_id'])->business_id,
            'code' => 'K'.fake()->unique()->numberBetween(1, 999),
            'name' => 'Tablet '.fake()->word(),
            'device_uid' => fake()->uuid(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
