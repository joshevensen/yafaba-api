<?php

namespace App\Jobs\Enrichment;

use App\Models\CardExplainer;
use App\Models\ComboPair;
use App\Services\Enrichment\ComboSynergyPlausibilityAuditor;
use App\Services\Enrichment\ExplainerGroundingChecker;
use App\Services\Enrichment\ExplainerValidationPromptBuilder;
use App\Services\Llm\LlmTransportFactory;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Self-validation step: gates `draft -> validated` promotion for
 * `card_explainers`, `combo_pairs`, and `card_synergy_tags`.
 *
 * Unlike the fan-out AI-task jobs, this is a single non-batched chain link
 * (no $cardId) — it loops over every relevant draft row itself in one run.
 *
 * card_explainers: a deterministic cited_rules check against the current CR
 * snapshot short-circuits an LLM call when it fails; otherwise an LLM
 * topical-consistency judgment decides promotion.
 *
 * combo_pairs / card_synergy_tags: an interim vector-similarity plausibility
 * audit (substituting for deferred self-play) scores every draft row, but
 * never itself promotes one — promotion requires a pre-existing
 * human_signoff_at, which nothing in this job sets.
 *
 * A transport failure here throws and propagates, halting the chain before
 * PublishEnrichment runs, per the chain-as-gate design.
 */
class ValidateEnrichment extends EnrichmentJob
{
    public function handle(
        ExplainerGroundingChecker $groundingChecker,
        ExplainerValidationPromptBuilder $promptBuilder,
        LlmTransportFactory $transportFactory,
        ComboSynergyPlausibilityAuditor $auditor,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->shouldSkipWrite('dry_run')) {
            return;
        }

        $this->validateExplainers($groundingChecker, $promptBuilder, $transportFactory);
        $this->validateCombosAndSynergies($auditor);
    }

    /**
     * @return array<int, RateLimited>
     */
    public function middleware(): array
    {
        return [new RateLimited('enrichment-llm'), new RateLimited('enrichment-embedding')];
    }

    protected function stepKey(): string
    {
        return 'validate';
    }

    private function validateExplainers(
        ExplainerGroundingChecker $groundingChecker,
        ExplainerValidationPromptBuilder $promptBuilder,
        LlmTransportFactory $transportFactory,
    ): void {
        $explainers = CardExplainer::with('card')
            ->where('status', 'draft')
            ->whereNotNull('explainer_text')
            ->where('explainer_text', '!=', '')
            ->get();

        foreach ($explainers as $explainer) {
            if ($explainer->card === null) {
                $this->logSkip('missing_card', ['card_id' => $explainer->card_id]);

                continue;
            }

            $unknownRules = $groundingChecker->unknownCitedRules($explainer);

            if ($unknownRules !== []) {
                $explainer->update([
                    'grounding_confidence' => 0.0,
                    'grounding_flag_reason' => 'Unknown cited rule(s): '.implode(', ', $unknownRules),
                ]);

                Log::info('enrichment.explainer_validated', [
                    'step' => $this->stepKey(),
                    'card_id' => $explainer->card_id,
                    'result' => 'flagged_deterministic',
                    'confidence' => 0.0,
                ]);

                continue;
            }

            $prompt = $promptBuilder->build($explainer->card, $explainer);

            $payload = $transportFactory->make($this->context->via)->complete(
                $prompt,
                $promptBuilder->schema(),
                (string) config('enrichment.models.explainer_validation'),
            );

            $confidence = max(0.0, min(1.0, (float) $payload['confidence']));
            $isConsistent = (bool) $payload['is_consistent'];

            $explainer->update([
                'status' => $isConsistent ? 'validated' : 'draft',
                'validated_at' => $isConsistent ? now() : null,
                'grounding_confidence' => $confidence,
                'grounding_flag_reason' => $isConsistent ? null : (string) $payload['reason'],
            ]);

            Log::info('enrichment.explainer_validated', [
                'step' => $this->stepKey(),
                'card_id' => $explainer->card_id,
                'result' => $isConsistent ? 'validated' : 'flagged_llm',
                'confidence' => $confidence,
            ]);
        }
    }

    private function validateCombosAndSynergies(ComboSynergyPlausibilityAuditor $auditor): void
    {
        $auditor->auditComboPairs();
        $auditor->auditSynergyTags();

        ComboPair::where('status', 'draft')->whereNotNull('human_signoff_at')->update(['status' => 'validated']);

        DB::table('card_synergy_tags')
            ->where('status', 'draft')
            ->whereNotNull('human_signoff_at')
            ->update(['status' => 'validated']);
    }
}
