<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CardExplainer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardExplainer>
 */
class CardExplainerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            'explainer_text' => fake()->paragraph(),
            'cited_rules' => ['1.2.3'],
            'status' => 'draft',
            'generated_at' => now(),
            'validated_at' => null,
        ];
    }

    public function validated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'validated',
            'validated_at' => now(),
        ]);
    }
}
