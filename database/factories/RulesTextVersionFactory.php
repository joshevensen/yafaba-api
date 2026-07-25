<?php

namespace Database\Factories;

use App\Models\RulesTextVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RulesTextVersion>
 */
class RulesTextVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fetched_at' => now(),
            'full_text' => fake()->paragraphs(5, true),
            'diff_from_previous' => null,
        ];
    }
}
