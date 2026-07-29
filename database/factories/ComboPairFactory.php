<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\ComboPair;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComboPair>
 */
class ComboPairFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_id_a' => Card::factory(),
            'card_id_b' => Card::factory(),
            'description' => fake()->sentence(),
            'status' => 'draft',
            'self_play_win_rate_delta' => null,
            'source_hash' => null,
            'prompt_version' => null,
            'generated_at' => null,
        ];
    }

    public function validated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'validated',
        ]);
    }
}
