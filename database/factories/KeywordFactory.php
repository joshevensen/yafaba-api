<?php

namespace Database\Factories;

use App\Models\Keyword;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Keyword>
 */
class KeywordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'rules_text' => fake()->sentence(),
            'explainer' => fake()->sentence(),
            'cited_rules' => ['1.2.3'],
            'notes' => null,
        ];
    }
}
