<?php

namespace Database\Factories;

use App\Models\KbDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KbDocument>
 */
class KbDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_type' => 'cr_rules',
            'source_ref' => fake()->numerify('#.#.#'),
            'content' => fake()->paragraph(),
            'embedding' => null,
            'embedding_model' => 'voyage-3.5',
            'trust_status' => 'ground_truth',
            'version' => 1,
            'effective_date' => null,
            'created_at' => now(),
        ];
    }

    public function embedded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'embedding' => '['.implode(',', array_fill(0, 1024, 0.1)).']',
        ]);
    }
}
