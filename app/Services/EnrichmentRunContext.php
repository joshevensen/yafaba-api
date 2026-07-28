<?php

namespace App\Services;

/**
 * Immutable run state for one enrich:run invocation, serialized into the
 * payload of every job it dispatches.
 */
final readonly class EnrichmentRunContext
{
    /**
     * @param  array<int, string>  $steps
     * @param  array<string, int>  $plannedCounts
     */
    public function __construct(
        public ?string $pipelineRunId,
        public array $steps,
        public bool $fresh,
        public bool $dryRun,
        public ?string $cardId,
        public string $via,
        public string $triggeredBy,
        public string $promptVersion,
        public array $plannedCounts = [],
    ) {}

    /**
     * @param  array<string, int>  $plannedCounts
     */
    public function withPlannedCounts(array $plannedCounts): self
    {
        return new self(
            pipelineRunId: $this->pipelineRunId,
            steps: $this->steps,
            fresh: $this->fresh,
            dryRun: $this->dryRun,
            cardId: $this->cardId,
            via: $this->via,
            triggeredBy: $this->triggeredBy,
            promptVersion: $this->promptVersion,
            plannedCounts: $plannedCounts,
        );
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function isSingleCard(): bool
    {
        return $this->cardId !== null;
    }
}
