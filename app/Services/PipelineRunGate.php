<?php

namespace App\Services;

use App\Models\PipelineRun;
use App\Models\SourceSyncState;
use Carbon\CarbonImmutable;

class PipelineRunGate
{
    public function __construct(private readonly PipelineRunRecorder $recorder) {}

    /**
     * Decide whether an enrichment run should proceed. Order of evaluation
     * matters: concurrency is checked before the force override, so a
     * force request never bypasses an already-running enrichment.
     */
    public function enrichmentShouldRun(bool $force = false): PipelineGateDecision
    {
        $this->recorder->reconcileStale();

        if ($this->recorder->isRunning(PipelineRun::PHASE_ENRICHMENT)) {
            return new PipelineGateDecision(false, PipelineGateDecision::REASON_ALREADY_RUNNING);
        }

        if ($force) {
            return new PipelineGateDecision(true, PipelineGateDecision::REASON_FORCED);
        }

        $lastRun = PipelineRun::forPhase(PipelineRun::PHASE_ENRICHMENT)->lastSuccessful()->first();

        if (! $lastRun) {
            return new PipelineGateDecision(true, PipelineGateDecision::REASON_NO_PRIOR_RUN);
        }

        $cooldownEnds = CarbonImmutable::parse($lastRun->finished_at)->addHours(config('enrichment.min_run_interval_hours'));

        if ($cooldownEnds->isFuture()) {
            return new PipelineGateDecision(false, PipelineGateDecision::REASON_COOLDOWN, retryAfter: $cooldownEnds);
        }

        $hasRelevantChange = SourceSyncState::whereIn('source_key', config('pipeline.enrichment_relevant_sources'))
            ->where('last_changed_at', '>', $lastRun->finished_at)
            ->exists();

        if ($hasRelevantChange) {
            return new PipelineGateDecision(true, PipelineGateDecision::REASON_SOURCE_CHANGED);
        }

        return new PipelineGateDecision(false, PipelineGateDecision::REASON_NO_RELEVANT_CHANGES);
    }
}
