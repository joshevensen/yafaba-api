<?php

namespace Database\Factories;

use App\Models\PipelineRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineRun>
 */
class PipelineRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phase' => PipelineRun::PHASE_DATA,
            'command' => 'data:ingest-cards',
            'started_at' => now(),
            'finished_at' => now(),
            'status' => PipelineRun::STATUS_SUCCESS,
            'triggered_by' => PipelineRun::TRIGGER_CLI,
            'counts' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'is_running' => false,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PipelineRun::STATUS_RUNNING,
            'finished_at' => null,
            'is_running' => true,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PipelineRun::STATUS_FAILED,
        ]);
    }

    public function partial(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PipelineRun::STATUS_PARTIAL,
        ]);
    }

    public function enrichment(): static
    {
        return $this->state(fn (array $attributes): array => [
            'phase' => PipelineRun::PHASE_ENRICHMENT,
            'command' => 'enrich:run',
        ]);
    }
}
