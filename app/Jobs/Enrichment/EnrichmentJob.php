<?php

namespace App\Jobs\Enrichment;

use App\Services\EnrichmentRunContext;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Base class for every job in the enrich:run pipeline.
 *
 * Provides shared queue/retry configuration, dry-run write-skipping, and
 * structured logging helpers used consistently across the concrete steps.
 */
abstract class EnrichmentJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries;

    public function __construct(public readonly EnrichmentRunContext $context)
    {
        $this->tries = (int) config('enrichment.job.tries');
        $this->onQueue((string) config('enrichment.queue'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('enrichment.job.backoff');
    }

    /**
     * Returns the step key this job represents (e.g. 'kb', 'explainer'), or
     * a boundary sentinel ('prepare'/'finalize') for non-step jobs.
     */
    abstract protected function stepKey(): string;

    /**
     * Logs the dry-run skip and reports whether the caller should skip its
     * write.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function shouldSkipWrite(string $reason, array $meta = []): bool
    {
        if (! $this->context->isDryRun()) {
            return false;
        }

        Log::info('enrichment.dry_run', array_merge([
            'step' => $this->stepKey(),
            'reason' => $reason,
        ], $meta));

        return true;
    }

    /**
     * Logs that a job skipped its work because its target was already
     * up to date.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function logSkip(string $reason, array $meta = []): void
    {
        Log::info('enrichment.skipped', array_merge([
            'step' => $this->stepKey(),
            'reason' => $reason,
        ], $meta));
    }
}
