<?php

namespace App\Jobs\Enrichment;

use App\Models\Card;
use App\Models\ComboPair;
use App\Models\KbDocument;
use App\Services\Enrichment\ComboCandidateRetriever;
use App\Services\Enrichment\ComboTaggingPromptBuilder;
use App\Services\Enrichment\KbRetriever;
use App\Services\EnrichmentRunContext;
use App\Services\Llm\LlmTransportFactory;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per-card combo-tagging step. Pools cards sharing a class/talent with the
 * target as candidates, grounds on validated combo KB chunks, makes a
 * structured-output Claude call proposing combo partners, and writes
 * accepted proposals as draft combo_pairs rows. Proposals are discarded
 * unless they name a real candidate (no self-pairs, no unknown cards), have
 * a non-empty description, and are deduplicated by partner. Existing
 * validated pairs are preserved and never re-proposed as drafts. Skips
 * entirely (no KB retrieval, no LLM call, no writes) when the candidate pool
 * is empty. Skips when the card already has an up-to-date combo pair row
 * generated with the same source_hash and prompt_version (unless --fresh
 * was requested).
 */
class TagCardCombos extends EnrichmentJob
{
    public function __construct(EnrichmentRunContext $context, public readonly string $cardId)
    {
        parent::__construct($context);
    }

    public function handle(ComboCandidateRetriever $candidateRetriever, KbRetriever $kbRetriever, ComboTaggingPromptBuilder $promptBuilder, LlmTransportFactory $transportFactory): void
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

        $card = Card::find($this->cardId);

        if ($card === null) {
            $this->logSkip('missing_card', ['card_id' => $this->cardId]);

            return;
        }

        $candidates = $candidateRetriever->retrieve(
            $card,
            (int) config('enrichment.retrieval.combo_candidate_top_k'),
            (int) config('enrichment.retrieval.combo_candidate_pool_limit'),
        );

        if ($candidates->isEmpty()) {
            $this->logSkip('no_candidates', ['card_id' => $this->cardId]);

            return;
        }

        $validatedExamples = $kbRetriever->retrieve(
            $card->functional_text ?? '',
            (int) config('enrichment.retrieval.combo_validated_examples_top_k'),
            KbDocument::TRUST_VALIDATED,
            KbDocument::SOURCE_COMBO,
        );

        $prompt = $promptBuilder->build($card, $candidates, $validatedExamples);
        $schema = $promptBuilder->schema();

        $payload = $transportFactory->make($this->context->via)->complete(
            $prompt,
            $schema,
            (string) config('enrichment.models.combo_tagging'),
        );

        $proposals = $this->normalizeProposals($payload['combos'] ?? [], $candidates->pluck('id')->all());

        $insertedCount = DB::transaction(function () use ($proposals, $card): int {
            $validatedPartnerIds = ComboPair::where('card_id_a', $this->cardId)
                ->where('status', 'validated')
                ->pluck('card_id_b')
                ->all();

            ComboPair::where('card_id_a', $this->cardId)->where('status', 'draft')->delete();

            $inserted = 0;

            foreach ($proposals as $proposal) {
                if (in_array($proposal['card_id_b'], $validatedPartnerIds, true)) {
                    continue;
                }

                ComboPair::create([
                    'card_id_a' => $this->cardId,
                    'card_id_b' => $proposal['card_id_b'],
                    'description' => $proposal['description'],
                    'status' => 'draft',
                    'source_hash' => $card->source_hash,
                    'prompt_version' => $this->context->promptVersion,
                    'generated_at' => now(),
                ]);

                $inserted++;
            }

            return $inserted;
        });

        Log::info('enrichment.combos_tagged', [
            'step' => $this->stepKey(),
            'card_id' => $this->cardId,
            'combo_count' => $insertedCount,
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
        return 'combo';
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

        $row = ComboPair::where('card_id_a', $this->cardId)->first();

        if ($row === null) {
            return false;
        }

        return $row->prompt_version === $this->context->promptVersion
            && $row->source_hash === $card->source_hash
            && $row->generated_at !== null
            && $row->generated_at->greaterThanOrEqualTo($card->updated_at);
    }

    /**
     * @param  array<int, mixed>  $proposals
     * @param  list<string>  $candidateIds
     * @return list<array{card_id_b: string, description: string}>
     */
    private function normalizeProposals(array $proposals, array $candidateIds): array
    {
        $seenPartnerIds = [];
        $normalized = [];

        foreach ($proposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $cardIdB = $proposal['card_id_b'] ?? null;

            if (! is_string($cardIdB) || trim($cardIdB) === '') {
                continue;
            }

            if ($cardIdB === $this->cardId) {
                continue;
            }

            if (! in_array($cardIdB, $candidateIds, true)) {
                continue;
            }

            $description = $proposal['description'] ?? null;

            if (! is_string($description) || trim($description) === '') {
                continue;
            }

            if (isset($seenPartnerIds[$cardIdB])) {
                continue;
            }

            $seenPartnerIds[$cardIdB] = true;
            $normalized[] = ['card_id_b' => $cardIdB, 'description' => $description];
        }

        return $normalized;
    }
}
