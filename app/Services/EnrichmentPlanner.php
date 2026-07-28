<?php

namespace App\Services;

use App\Jobs\Enrichment\BuildKnowledgeBase;
use App\Jobs\Enrichment\FinalizeEnrichmentRun;
use App\Jobs\Enrichment\GenerateCardExplainer;
use App\Jobs\Enrichment\PrepareEnrichmentRun;
use App\Jobs\Enrichment\PublishEnrichment;
use App\Jobs\Enrichment\TagCardCombos;
use App\Jobs\Enrichment\TagCardSynergies;
use App\Jobs\Enrichment\ValidateEnrichment;
use App\Models\Card;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;

/**
 * Resolves --only into an ordered step list, scopes which cards each fan-out
 * step needs to process, and builds the chain/batch structure enrich:run
 * dispatches.
 */
class EnrichmentPlanner
{
    private const FAN_OUT_STEPS = ['explainer', 'combo', 'synergy'];

    /** @var array<string, Collection<int, string>> memoizes cardScopeFor() per step for one planning pass */
    private array $cardScopeCache = [];

    /**
     * Resolve the ordered subset of config('enrichment.steps') to run.
     *
     * @return array<int, string>
     */
    public function resolveSteps(?string $only): array
    {
        $allSteps = config('enrichment.steps');

        if ($only === null || trim($only) === '') {
            return $allSteps;
        }

        $requested = array_values(array_filter(array_map('trim', explode(',', $only)), fn (string $step): bool => $step !== ''));
        $unknown = array_diff($requested, $allSteps);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown enrichment step(s): '.implode(', ', $unknown));
        }

        return array_values(array_filter($allSteps, fn (string $step): bool => in_array($step, $requested, true)));
    }

    /**
     * The coarse card scope for a fan-out step: every card when --fresh,
     * otherwise cards whose target row is missing or stale. In single-card
     * mode this is always exactly the one requested card.
     */
    public function cardScopeFor(string $step, EnrichmentRunContext $context): Collection
    {
        if ($context->isSingleCard()) {
            return collect([$context->cardId]);
        }

        if (! in_array($step, self::FAN_OUT_STEPS, true)) {
            return collect();
        }

        $cacheKey = $step.'|'.($context->fresh ? '1' : '0');

        return $this->cardScopeCache[$cacheKey] ??= $this->queryCardScopeFor($step, $context);
    }

    private function queryCardScopeFor(string $step, EnrichmentRunContext $context): Collection
    {
        $query = Card::query();

        if (! $context->fresh) {
            match ($step) {
                'explainer' => $query->where(function ($q): void {
                    $q->whereDoesntHave('explainer')
                        ->orWhereHas('explainer', function ($eq): void {
                            $eq->whereNull('generated_at')
                                ->orWhereColumn('card_explainers.generated_at', '<', 'cards.updated_at');
                        });
                }),
                'combo' => $query->whereDoesntHave('comboPairs'),
                'synergy' => $query->whereDoesntHave('synergyTags'),
                default => null,
            };
        }

        return $query->pluck('id');
    }

    /**
     * Build the ordered array of job instances and pending batches to hand
     * to Bus::chain(). Single-card mode returns a flat array of per-card
     * jobs only, with no batches and no Prepare/Finalize boundary jobs.
     *
     * @return array<int, mixed>
     */
    public function buildChain(EnrichmentRunContext $context): array
    {
        if ($context->isSingleCard()) {
            return $this->buildSingleCardChain($context);
        }

        $chain = [new PrepareEnrichmentRun($context)];

        foreach ($context->steps as $step) {
            $link = $this->buildLinkFor($step, $context);

            if ($link !== null) {
                $chain[] = $link;
            }
        }

        $chain[] = new FinalizeEnrichmentRun($context);

        return $chain;
    }

    /**
     * Per-step job counts, for the command's printed summary and the
     * PipelineRun counts.
     *
     * @return array<string, int>
     */
    public function plannedCounts(EnrichmentRunContext $context): array
    {
        $counts = [];

        foreach ($context->steps as $step) {
            $counts[$step] = in_array($step, self::FAN_OUT_STEPS, true)
                ? $this->cardScopeFor($step, $context)->count()
                : 1;
        }

        return $counts;
    }

    /**
     * @return array<int, mixed>|null a chain link (job or pending batch), or
     *                                null when this step contributes nothing
     */
    private function buildLinkFor(string $step, EnrichmentRunContext $context): mixed
    {
        return match ($step) {
            'kb' => new BuildKnowledgeBase($context),
            'validate' => new ValidateEnrichment($context),
            'publish' => new PublishEnrichment($context),
            'explainer', 'combo', 'synergy' => $this->buildBatchFor($step, $context),
            default => null,
        };
    }

    private function buildBatchFor(string $step, EnrichmentRunContext $context): mixed
    {
        $cardIds = $this->cardScopeFor($step, $context);

        if ($cardIds->isEmpty()) {
            return null;
        }

        $jobs = $cardIds->map(fn (string $cardId) => match ($step) {
            'explainer' => new GenerateCardExplainer($context, $cardId),
            'combo' => new TagCardCombos($context, $cardId),
            'synergy' => new TagCardSynergies($context, $cardId),
        })->all();

        return Bus::batch($jobs)->name("enrichment:{$step}")->allowFailures();
    }

    /**
     * @return array<int, mixed>
     */
    private function buildSingleCardChain(EnrichmentRunContext $context): array
    {
        $chain = [];

        foreach ($context->steps as $step) {
            $chain[] = match ($step) {
                'kb' => new BuildKnowledgeBase($context),
                'explainer' => new GenerateCardExplainer($context, (string) $context->cardId),
                'combo' => new TagCardCombos($context, (string) $context->cardId),
                'synergy' => new TagCardSynergies($context, (string) $context->cardId),
                'validate' => new ValidateEnrichment($context),
                'publish' => new PublishEnrichment($context),
                default => null,
            };
        }

        return array_values(array_filter($chain));
    }
}
