<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => ucwords(fake()->words(2, true)),
            'price' => fake()->numberBetween(10, 50) * 1000,
        ];
    }

    public function archived(): static
    {
        return $this->state(['is_active' => false]);
    }
}
