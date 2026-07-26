<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\MetaSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetaSnapshot>
 */
class MetaSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hero_id' => Card::factory()->hero(),
            'format' => 'CC',
            'win_rate' => fake()->randomFloat(4, 0.3, 0.7),
            'sample_size' => fake()->numberBetween(10, 5000),
            'source' => 'fabtcgmeta',
            'fetched_at' => now(),
        ];
    }
}
