<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModifierGroup>
 */
class ModifierGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'min_select' => 0,
            'max_select' => null,
        ];
    }

    /** Wajib pilih tepat satu (mis. level gula). */
    public function pickOne(): static
    {
        return $this->state(['min_select' => 1, 'max_select' => 1]);
    }
}
