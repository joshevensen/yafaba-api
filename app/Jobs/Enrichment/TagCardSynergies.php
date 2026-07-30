<?php

namespace App\Jobs\Enrichment;

use App\Models\Card;
use App\Models\KbDocument;
use App\Models\SynergyTag;
use App\Services\Enrichment\KbRetriever;
use App\Services\Enrichment\SynergyTaggingPromptBuilder;
use App\Services\EnrichmentRunContext;
use App\Services\Llm\LlmTransportFactory;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per-card synergy-tagging step. Grounds on the established synergy_tags
 * vocabulary and validated synergy KB chunks, makes a structured-output
 * Claude call proposing tag assignments (and new-tag proposals), and writes
 * accepted assignments as draft card_synergy_tags rows. Assignments are
 * discarded unless they name a tag in the supplied vocabulary; new-tag
 * proposals are discarded unless their name is non-blank and does not
 * collide with any existing synergy_tags.name. Existing validated rows are
 * preserved and never re-proposed as drafts. Skips entirely (no KB
 * retrieval, no LLM call, no writes) when the card has no functional text.
 * Skips when every draft card_synergy_tags row for the card is already
 * up to date with the same source_hash and prompt_version (unless --fresh
 * was requested).
 */
class TagCardSynergies extends EnrichmentJob
{
    public function __construct(EnrichmentRunContext $context, public readonly string $cardId)
    {
        parent::__construct($context);
    }

    public function handle(KbRetriever $kbRetriever, SynergyTaggingPromptBuilder $promptBuilder, LlmTransportFactory $transportFactory): void
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

        $functionalText = trim((string) $card->functional_text);

        if ($functionalText === '') {
            $this->logSkip('no_functional_text', ['card_id' => $this->cardId]);

            return;
        }

        $vocabulary = SynergyTag::where('status', 'approved')->orderBy('name')->get();

        $validatedExamples = $kbRetriever->retrieve(
            $functionalText,
            (int) config('enrichment.retrieval.synergy_validated_examples_top_k'),
            KbDocument::TRUST_VALIDATED,
            KbDocument::SOURCE_SYNERGY,
        );

        $prompt = $promptBuilder->build($card, $vocabulary, $validatedExamples);
        $schema = $promptBuilder->schema();

        $payload = $transportFactory->make($this->context->via)->complete(
            $prompt,
            $schema,
            (string) config('enrichment.models.synergy_tagging'),
        );

        $tagAssignments = $this->normalizeTagAssignments($payload['tags'] ?? [], $vocabulary->pluck('id')->all());
        $newTagProposals = $this->normalizeNewTagProposals($payload['new_tags'] ?? []);

        [$tagCount, $newTagCount] = DB::transaction(function () use ($tagAssignments, $newTagProposals, $card): array {
            $preservedTagIds = DB::table('card_synergy_tags')
                ->where('card_id', $this->cardId)
                ->where('status', '!=', 'draft')
                ->pluck('synergy_tag_id')
                ->all();

            $insertedTagIds = [];

            foreach ($newTagProposals as $proposal) {
                $newTag = SynergyTag::create([
                    'name' => $proposal['name'],
                    'description' => $proposal['description'],
                    'status' => 'proposed',
                ]);

                $insertedTagIds[] = $newTag->id;
            }

            DB::table('card_synergy_tags')->where('card_id', $this->cardId)->where('status', 'draft')->delete();

            $assignmentIds = array_values(array_unique(array_diff(
                array_merge($tagAssignments, $insertedTagIds),
                $preservedTagIds,
            )));

            foreach ($assignmentIds as $tagId) {
                DB::table('card_synergy_tags')->insert([
                    'card_id' => $this->cardId,
                    'synergy_tag_id' => $tagId,
                    'status' => 'draft',
                    'source_hash' => $card->source_hash,
                    'prompt_version' => $this->context->promptVersion,
                    'generated_at' => now(),
                ]);
            }

            return [count($assignmentIds), count($insertedTagIds)];
        });

        Log::info('enrichment.synergies_tagged', [
            'step' => $this->stepKey(),
            'card_id' => $this->cardId,
            'tag_count' => $tagCount,
            'new_tag_count' => $newTagCount,
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
        return 'synergy';
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

        $draftRows = DB::table('card_synergy_tags')
            ->where('card_id', $this->cardId)
            ->where('status', 'draft');

        if (! $draftRows->clone()->exists()) {
            return false;
        }

        return ! $draftRows->clone()
            ->where(function ($q) use ($card): void {
                $q->where('prompt_version', '!=', $this->context->promptVersion)
                    ->orWhereNull('prompt_version')
                    ->orWhere('source_hash', '!=', $card->source_hash)
                    ->orWhereNull('source_hash')
                    ->orWhereNull('generated_at')
                    ->orWhere('generated_at', '<', $card->updated_at);
            })
            ->exists();
    }

    /**
     * @param  array<int, mixed>  $tags
     * @param  list<string>  $vocabularyIds
     * @return list<string>
     */
    private function normalizeTagAssignments(array $tags, array $vocabularyIds): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            if (! is_array($tag)) {
                continue;
            }

            $tagId = $tag['synergy_tag_id'] ?? null;

            if (! is_string($tagId) || trim($tagId) === '') {
                continue;
            }

            if (! in_array($tagId, $vocabularyIds, true)) {
                continue;
            }

            if (in_array($tagId, $normalized, true)) {
                continue;
            }

            $normalized[] = $tagId;
        }

        return $normalized;
    }

    /**
     * @param  array<int, mixed>  $newTags
     * @return list<array{name: string, description: string}>
     */
    private function normalizeNewTagProposals(array $newTags): array
    {
        $existingNames = SynergyTag::pluck('name')
            ->map(fn (string $name): string => mb_strtolower($name))
            ->all();

        $seenNames = [];
        $normalized = [];

        foreach ($newTags as $newTag) {
            if (! is_array($newTag)) {
                continue;
            }

            $name = $newTag['name'] ?? null;
            $description = $newTag['description'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            if (! is_string($description) || trim($description) === '') {
                continue;
            }

            $name = trim($name);
            $lowerName = mb_strtolower($name);

            if (isset($seenNames[$lowerName])) {
                continue;
            }

            if (in_array($lowerName, $existingNames, true)) {
                continue;
            }

            $seenNames[$lowerName] = true;
            $normalized[] = ['name' => $name, 'description' => trim($description)];
        }

        return $normalized;
    }
}
