<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\MenuCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuCategory>
 */
class MenuCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => ucfirst(fake()->unique()->word()),
        ];
    }
}
