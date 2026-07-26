<?php

namespace App\Services;

use App\Models\PipelineRun;
use Illuminate\Support\Facades\Cache;

class PipelineRunRecorder
{
    /**
     * Reconcile any stale runs, then attempt to acquire the phase lock and
     * create a new running PipelineRun row. Returns null if another run for
     * the same phase currently holds the lock.
     */
    public function start(string $phase, string $command, string $triggeredBy): ?PipelineRun
    {
        $this->reconcileStale();

        $lock = Cache::lock("pipeline-run:{$phase}", config('pipeline.lock_ttl_seconds'));

        if (! $lock->get()) {
            return null;
        }

        return PipelineRun::create([
            'phase' => $phase,
            'command' => $command,
            'triggered_by' => $triggeredBy,
            'status' => PipelineRun::STATUS_RUNNING,
            'is_running' => true,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }

    /**
     * Mark the given run as finished with the given terminal status and
     * counts, then release that phase's lock.
     *
     * @param  array<string, mixed>  $counts
     */
    public function finish(PipelineRun $run, string $status, array $counts = []): PipelineRun
    {
        $run->finished_at = now();
        $run->status = $status;
        $run->counts = $counts;
        $run->is_running = false;
        $run->save();

        Cache::lock("pipeline-run:{$run->phase}")->forceRelease();

        return $run;
    }

    /**
     * Mark the given run as failed with the given error and counts, then
     * release that phase's lock.
     *
     * @param  array<string, mixed>  $counts
     */
    public function fail(PipelineRun $run, string $error, array $counts = []): PipelineRun
    {
        $run->finished_at = now();
        $run->status = PipelineRun::STATUS_FAILED;
        $run->counts = $counts;
        $run->is_running = false;
        $run->error = $error;
        $run->save();

        Cache::lock("pipeline-run:{$run->phase}")->forceRelease();

        return $run;
    }

    /**
     * Whether a PipelineRun row for the given phase is currently marked as
     * running. Does not reconcile staleness itself.
     */
    public function isRunning(string $phase): bool
    {
        return PipelineRun::forPhase($phase)->where('is_running', true)->exists();
    }

    /**
     * Mark any running PipelineRun rows whose started_at is older than the
     * configured staleness window as failed, and release their locks.
     * Returns the number of rows reconciled.
     */
    public function reconcileStale(): int
    {
        $staleBefore = now()->subMinutes(config('pipeline.stale_run_after_minutes'));

        $staleRuns = PipelineRun::where('is_running', true)
            ->where('started_at', '<', $staleBefore)
            ->get();

        foreach ($staleRuns as $run) {
            $run->status = PipelineRun::STATUS_FAILED;
            $run->is_running = false;
            $run->finished_at = now();
            $run->error = 'Run marked stale by reconciliation';
            $run->save();

            Cache::lock("pipeline-run:{$run->phase}")->forceRelease();
        }

        return $staleRuns->count();
    }
}
