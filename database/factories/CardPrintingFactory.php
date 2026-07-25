<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CardPrinting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardPrinting>
 */
class CardPrintingFactory extends Factory
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
            'set_code' => fake()->regexify('[A-Z]{3}'),
            'rarity' => 'Rare',
            'finish' => 'standard',
            'art_variant' => null,
            'image_url' => null,
            'cardvault_print_id' => fake()->unique()->regexify('[A-Z]{3}[0-9]{3}'),
            'price_cache' => null,
            'price_updated_at' => null,
        ];
    }
}
