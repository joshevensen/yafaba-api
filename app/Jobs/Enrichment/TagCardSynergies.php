<?php

namespace App\Jobs\Enrichment;

use App\Services\EnrichmentRunContext;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stub for the per-card synergy-tagging step. Skips when the card already
 * has at least one synergy tag recorded (unless --fresh was requested).
 */
class TagCardSynergies extends EnrichmentJob
{
    public function __construct(EnrichmentRunContext $context, public readonly string $cardId)
    {
        parent::__construct($context);
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->isUpToDate()) {
            $this->logSkip('up_to_date', ['card_id' => $this->cardId]);

            return;
        }

        if ($this->shouldSkipWrite('dry_run', ['card_id' => $this->cardId])) {
            return;
        }

        Log::info('enrichment.stub', ['step' => $this->stepKey(), 'card_id' => $this->cardId]);
    }

    /**
     * @return array<int, RateLimited>
     */
    public function middleware(): array
    {
        return [new RateLimited('enrichment-llm')];
    }

    protected function stepKey(): string
    {
        return 'synergy';
    }

    private function isUpToDate(): bool
    {
        if ($this->context->fresh) {
            return false;
        }

        return DB::table('card_synergy_tags')->where('card_id', $this->cardId)->exists();
    }
}
