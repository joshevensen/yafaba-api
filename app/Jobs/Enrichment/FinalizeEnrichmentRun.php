<?php

namespace App\Jobs\Enrichment;

use App\Models\PipelineRun;
use App\Services\PipelineRunRecorder;

/**
 * Finalizes the enrich:run pipeline run, recording completion when running
 * in pipeline (multi-card) mode. Single-card mode has no PipelineRun to
 * finalize and is a no-op.
 */
class FinalizeEnrichmentRun extends EnrichmentJob
{
    public function handle(PipelineRunRecorder $recorder): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->context->pipelineRunId === null) {
            return;
        }

        $run = PipelineRun::find($this->context->pipelineRunId);

        if ($run === null) {
            return;
        }

        $recorder->finish($run, PipelineRun::STATUS_SUCCESS, array_merge(
            $this->context->plannedCounts,
            ['dry_run' => $this->context->isDryRun()],
        ));
    }

    protected function stepKey(): string
    {
        return 'finalize';
    }
}
