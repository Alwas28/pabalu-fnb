<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Outlet>
 */
class OutletFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'code' => strtoupper(fake()->unique()->bothify('OUT###')),
            'name' => 'Outlet '.fake()->city(),
            'address' => fake()->address(),
        ];
    }
}
