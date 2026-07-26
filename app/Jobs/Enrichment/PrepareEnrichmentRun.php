<?php

namespace App\Jobs\Enrichment;

use Illuminate\Support\Facades\Log;

/**
 * Preflight job for enrich:run: logs the resolved plan. No writes, so no
 * idempotency guard is needed.
 */
class PrepareEnrichmentRun extends EnrichmentJob
{
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info('enrichment.plan', [
            'steps' => $this->context->steps,
            'fresh' => $this->context->fresh,
            'dry_run' => $this->context->dryRun,
            'via' => $this->context->via,
            'prompt_version' => $this->context->promptVersion,
        ]);
    }

    protected function stepKey(): string
    {
        return 'prepare';
    }
}
