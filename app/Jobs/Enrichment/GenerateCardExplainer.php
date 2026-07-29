<?php

namespace App\Jobs\Enrichment;

use App\Models\Card;
use App\Models\CardExplainer;
use App\Models\ErrataBulletin;
use App\Services\Enrichment\CardExplainerPromptBuilder;
use App\Services\Enrichment\KbRetriever;
use App\Services\EnrichmentRunContext;
use App\Services\Llm\LlmTransportFactory;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

/**
 * Per-card explainer generation step. Grounds on the card's functional_text,
 * its keywords' glossary text, top-k ground_truth KB chunks, and any
 * affecting errata; makes a structured-output Claude call; writes the result
 * as a draft card_explainers row. Skips when the card already has an
 * explainer generated at or after its last update, with the same
 * source_hash and prompt_version (unless --fresh was requested).
 */
class GenerateCardExplainer extends EnrichmentJob
{
    public function __construct(EnrichmentRunContext $context, public readonly string $cardId)
    {
        parent::__construct($context);
    }

    public function handle(KbRetriever $retriever, CardExplainerPromptBuilder $promptBuilder, LlmTransportFactory $transportFactory): void
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

        $card = Card::with('keywords')->find($this->cardId);

        if ($card === null) {
            $this->logSkip('missing_card', ['card_id' => $this->cardId]);

            return;
        }

        $queryText = $this->groundingQueryText($card);
        $topK = (int) config('enrichment.retrieval.card_explainer_top_k');
        $chunks = $queryText === '' ? collect() : $retriever->retrieve($queryText, $topK);
        $errata = ErrataBulletin::query()->affectingCard($this->cardId)->get();

        $prompt = $promptBuilder->build($card, $chunks, $errata);
        $schema = $promptBuilder->schema();

        $payload = $transportFactory->make($this->context->via)->complete(
            $prompt,
            $schema,
            (string) config('enrichment.models.card_explainer'),
        );

        CardExplainer::updateOrCreate(['card_id' => $this->cardId], [
            'explainer_text' => $payload['explainer_text'],
            'cited_rules' => $this->normalizeCitedRules($payload['cited_rules'] ?? []),
            'status' => 'draft',
            'generated_at' => now(),
            'validated_at' => null,
            'source_hash' => $card->source_hash,
            'prompt_version' => $this->context->promptVersion,
        ]);

        Log::info('enrichment.explainer_generated', [
            'step' => $this->stepKey(),
            'card_id' => $this->cardId,
            'cited_rules_count' => count($payload['cited_rules'] ?? []),
        ]);
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

        return $explainer->prompt_version === $this->context->promptVersion
            && $explainer->source_hash === $card->source_hash
            && $explainer->generated_at->greaterThanOrEqualTo($card->updated_at);
    }

    private function groundingQueryText(Card $card): string
    {
        $parts = array_filter([
            $card->functional_text,
            ...$card->keywords->map(fn ($keyword) => $keyword->explainer ?? $keyword->rules_text)->filter()->all(),
        ]);

        return trim(implode("\n", $parts));
    }

    /**
     * @param  array<int, mixed>  $citedRules
     * @return list<string>
     */
    private function normalizeCitedRules(array $citedRules): array
    {
        return array_values(array_filter(
            array_map('strval', $citedRules),
            fn (string $rule): bool => trim($rule) !== '',
        ));
    }
}
