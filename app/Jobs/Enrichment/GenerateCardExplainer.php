<?php

namespace App\Jobs\Enrichment;

use App\Models\Card;
use App\Models\CardExplainer;
use App\Services\EnrichmentRunContext;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

/**
 * Stub for the per-card explainer generation step. Skips when the card
 * already has an explainer generated at or after its last update (unless
 * --fresh was requested).
 */
class GenerateCardExplainer extends EnrichmentJob
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
        return 'explainer';
    }

    private function isUpToDate(): bool
    {
        if ($this->context->fresh) {
            return false;
        }

        $card = Card::find($this->cardId);

        if ($card === null) {
            return false;
        }

        $explainer = CardExplainer::find($this->cardId);

        if ($explainer === null || $explainer->generated_at === null) {
            return false;
        }

        return $explainer->generated_at->greaterThanOrEqualTo($card->updated_at);
    }
}
