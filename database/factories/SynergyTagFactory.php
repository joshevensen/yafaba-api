<?php

namespace Database\Factories;

use App\Models\SynergyTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SynergyTag>
 */
class SynergyTagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(3, false),
            'description' => fake()->sentence(),
        ];
    }
}
