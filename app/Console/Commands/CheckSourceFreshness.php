<?php

namespace App\Console\Commands;

use App\Models\PipelineRun;
use App\Services\PipelineRunRecorder;
use App\Services\SourceFreshnessChecker;
use App\Services\SourceFreshnessResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('data:check-sources {--source=* : Limit the check to the given source key(s)} {--check-only : Refresh fingerprints without running any ingest} {--force : Run the mapped ingest regardless of fingerprint} {--triggered-by=cli : scheduled|manual|cli}')]
#[Description('Check each external data source for upstream changes and run only the ingests whose source changed.')]
class CheckSourceFreshness extends Command
{
    private int $checked = 0;

    private int $changed = 0;

    private int $ingested = 0;

    private int $failed = 0;

    private ?PipelineRun $run = null;

    public function __construct(
        private readonly SourceFreshnessChecker $checker,
        private readonly PipelineRunRecorder $recorder,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->run = $this->recorder->start(PipelineRun::PHASE_DATA, 'data:check-sources', (string) $this->option('triggered-by'));

        if ($this->run === null) {
            $this->warn('Another data pipeline run is already in progress; skipping.');

            return Command::SUCCESS;
        }

        $sourceKeys = $this->resolveSourceKeys();

        if ($sourceKeys === null) {
            return Command::FAILURE;
        }

        foreach ($sourceKeys as $sourceKey) {
            $this->processSource($sourceKey);
        }

        $status = $this->resolveStatus();

        $this->recorder->finish($this->run, $status, [
            'checked' => $this->checked,
            'changed' => $this->changed,
            'ingested' => $this->ingested,
            'failed' => $this->failed,
        ]);

        $this->info("Source freshness check complete: {$this->checked} checked, {$this->changed} changed, {$this->ingested} ingested, {$this->failed} failed.");

        return $status === PipelineRun::STATUS_FAILED ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Resolve the list of source keys to process, validating any explicitly
     * given via --source. Returns null (after failing the run) when an
     * unknown source key is given.
     *
     * @return array<int, string>|null
     */
    private function resolveSourceKeys(): ?array
    {
        $requested = $this->option('source');
        $knownKeys = array_keys(config('pipeline.sources'));

        if ($requested === []) {
            return $knownKeys;
        }

        $unknown = array_diff($requested, $knownKeys);

        if ($unknown !== []) {
            $unknownList = implode(', ', $unknown);
            $this->error("Unknown source key(s): {$unknownList}");
            $this->recorder->fail($this->run, "Unknown source key(s): {$unknownList}");

            return null;
        }

        return $requested;
    }

    /**
     * Probe one source and, if warranted, run its mapped ingest command.
     */
    private function processSource(string $sourceKey): void
    {
        $result = $this->checker->check($sourceKey);
        $this->checked++;

        if ($result->probeFailed) {
            Log::warning('Source freshness probe failed', [
                'source_key' => $sourceKey,
                'error' => $result->error,
            ]);
            $this->failed++;

            return;
        }

        if ($result->changed) {
            $this->changed++;
        }

        if (! $this->shouldIngest($result)) {
            return;
        }

        $this->ingest($sourceKey, $result);
    }

    /**
     * Decide whether the given (successfully probed) source should be
     * ingested this run.
     */
    private function shouldIngest(SourceFreshnessResult $result): bool
    {
        if ($this->option('check-only')) {
            return false;
        }

        if ($this->option('force')) {
            return true;
        }

        return $result->changed;
    }

    /**
     * Run the mapped ingest command for a source and record the outcome.
     */
    private function ingest(string $sourceKey, SourceFreshnessResult $result): void
    {
        $command = config("pipeline.sources.{$sourceKey}.command");
        $exitCode = $this->call($command);

        if ($exitCode === Command::SUCCESS) {
            $this->checker->markPulled($sourceKey, $result->fingerprint);
            $this->ingested++;

            return;
        }

        Log::warning('Ingest command failed for source', [
            'source_key' => $sourceKey,
            'command' => $command,
            'exit_code' => $exitCode,
        ]);
        $this->failed++;
    }

    /**
     * Determine the overall terminal status for this run: FAILED only when
     * every processed source failed, PARTIAL when some (but not all) failed,
     * SUCCESS otherwise.
     */
    private function resolveStatus(): string
    {
        if ($this->failed === 0) {
            return PipelineRun::STATUS_SUCCESS;
        }

        if ($this->failed >= $this->checked) {
            return PipelineRun::STATUS_FAILED;
        }

        return PipelineRun::STATUS_PARTIAL;
    }
}
