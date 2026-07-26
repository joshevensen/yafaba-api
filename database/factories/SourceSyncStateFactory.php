<?php

namespace Database\Factories;

use App\Models\SourceSyncState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceSyncState>
 */
class SourceSyncStateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_key' => fake()->unique()->randomElement([
                SourceSyncState::SOURCE_FAB_CUBE_CARDS,
                SourceSyncState::SOURCE_CARDVAULT_PRINTINGS,
                SourceSyncState::SOURCE_TCGCSV_PRICING,
                SourceSyncState::SOURCE_ERRATA_BULLETINS,
                SourceSyncState::SOURCE_FABTCG_HERO_SELECTION,
                SourceSyncState::SOURCE_FABREC_STAPLE_STATS,
                SourceSyncState::SOURCE_META_SNAPSHOTS,
                SourceSyncState::SOURCE_FAB_CR_RULES,
            ]),
            'fingerprint' => fake()->sha1(),
            'fingerprint_type' => SourceSyncState::FINGERPRINT_CONTENT_HASH,
            'pulled_fingerprint' => null,
            'last_checked_at' => now(),
            'last_changed_at' => null,
            'last_pulled_at' => null,
            'last_error' => null,
        ];
    }

    public function pulled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_pulled_at' => now(),
            'pulled_fingerprint' => $attributes['fingerprint'],
        ]);
    }

    public function changed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'pulled_fingerprint' => fake()->sha1(),
            'last_changed_at' => now(),
        ]);
    }
}
