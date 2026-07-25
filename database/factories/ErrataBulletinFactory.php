<?php

namespace Database\Factories;

use App\Models\ErrataBulletin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErrataBulletin>
 */
class ErrataBulletinFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bulletin_number' => (string) fake()->unique()->numberBetween(1, 999),
            'url' => fake()->url(),
            'published_date' => fake()->date(),
            'content' => '<p>'.fake()->sentence().'</p>',
            'affected_card_ids' => [],
            'cached_at' => now(),
        ];
    }
}
