<?php

namespace App\Jobs\Enrichment;

use App\Models\ErrataBulletin;
use App\Models\KbDocument;
use App\Models\RulesTextVersion;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

/**
 * Stub for the knowledge-base build step. Skips when a KB document already
 * exists that is newer than the latest rules text and errata sources
 * (unless --fresh was requested).
 */
class BuildKnowledgeBase extends EnrichmentJob
{
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->isUpToDate()) {
            $this->logSkip('up_to_date');

            return;
        }

        if ($this->shouldSkipWrite('dry_run')) {
            return;
        }

        Log::info('enrichment.stub', ['step' => $this->stepKey()]);
    }

    /**
     * @return array<int, RateLimited>
     */
    public function middleware(): array
    {
        return [new RateLimited('enrichment-embedding')];
    }

    protected function stepKey(): string
    {
        return 'kb';
    }

    private function isUpToDate(): bool
    {
        if ($this->context->fresh) {
            return false;
        }

        $latestRulesFetch = RulesTextVersion::max('fetched_at');
        $latestErrataCache = ErrataBulletin::max('cached_at');

        if ($latestRulesFetch === null || $latestErrataCache === null) {
            return false;
        }

        return KbDocument::where('created_at', '>=', $latestRulesFetch)
            ->where('created_at', '>=', $latestErrataCache)
            ->exists();
    }
}
