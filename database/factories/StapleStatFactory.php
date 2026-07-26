<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\StapleStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StapleStat>
 */
class StapleStatFactory extends Factory
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
            'card_id' => Card::factory(),
            'inclusion_rate' => fake()->randomFloat(4, 0, 1),
            'source' => 'fabrec.gg',
            'fetched_at' => now(),
        ];
    }
}
