<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CardType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'card_type_id' => fn () => CardType::firstOrCreate(['name' => 'action'], ['display_order' => 1])->id,
            'pitch_value' => 1,
            'cost' => '1',
            'power' => null,
            'defense' => 2,
            'functional_text' => null,
            'hero_profile_id' => null,
            'age' => null,
            'source_id' => fake()->unique()->uuid(),
            'source_hash' => null,
            'updated_at' => now(),
        ];
    }
}
