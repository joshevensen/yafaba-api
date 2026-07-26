<?php

namespace App\Services;

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

    public function includes(string $step): bool
    {
        return in_array($step, $this->steps, true);
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
