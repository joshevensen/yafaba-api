<?php

namespace App\Jobs\Enrichment;

use App\Models\ErrataBulletin;
use App\Models\KbDocument;
use App\Models\RulesTextVersion;
use App\Services\Enrichment\KbChunker;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Builds the knowledge base: chunks the latest Comprehensive Rules text,
 * every errata bulletin, and validated explainer/combo/synergy notes, then
 * embeds and persists them into kb_documents. Also sweeps for kb_documents
 * rows whose embedding_model has fallen behind the configured model (or
 * whose embedding is missing, e.g. from an interrupted prior run) and
 * re-embeds them, even when their source_type has no fresh content this run.
 *
 * Skips when a KB document already exists that is newer than the latest
 * rules text and errata sources and every row already matches the
 * configured embedding model (unless --fresh was requested).
 *
 * Rows superseded by a rebuild are deleted only after their replacements
 * have been confirmed written — never before dispatch — so a Voyage outage
 * or a killed/retried job can leave duplicates but never silently loses KB
 * content. EmbedKbChunks jobs run on a dedicated queue (see
 * config('enrichment.embedding.queue')) rather than this job's own queue,
 * because this job blocks waiting for them: sharing a queue would let a
 * single worker deadlock against itself.
 */
class BuildKnowledgeBase extends EnrichmentJob
{
    public function handle(KbChunker $chunker): void
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

        $freshChunks = $this->gatherFreshChunks($chunker);
        $touchedSourceTypes = collect($freshChunks)->pluck('source_type')->unique()->values()->all();

        $staleRows = $this->staleRowsOutsideTouchedTypes($touchedSourceTypes);
        $reEmbedChunks = $staleRows->map(fn (KbDocument $row): array => [
            'source_type' => $row->source_type,
            'source_ref' => $row->source_ref,
            'content' => (string) $row->content,
            'trust_status' => $row->trust_status,
            'effective_date' => $row->effective_date?->toDateString(),
        ])->all();

        $idsToRetire = $touchedSourceTypes !== []
            ? KbDocument::whereIn('source_type', $touchedSourceTypes)->pluck('id')->all()
            : [];
        $idsToRetire = array_merge($idsToRetire, $staleRows->pluck('id')->all());

        $this->embedAndRetire(array_merge($freshChunks, $reEmbedChunks), $idsToRetire);
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

        $contentIsFresh = KbDocument::where('created_at', '>=', $latestRulesFetch)
            ->where('created_at', '>=', $latestErrataCache)
            ->exists();

        if (! $contentIsFresh) {
            return false;
        }

        return ! $this->staleRowsOutsideTouchedTypes([])->isNotEmpty();
    }

    /**
     * @return list<array{source_type: string, source_ref: ?string, content: string, trust_status: string, effective_date: ?string}>
     */
    private function gatherFreshChunks(KbChunker $chunker): array
    {
        $chunks = [];

        $latestRules = RulesTextVersion::query()->orderByDesc('fetched_at')->orderByDesc('id')->first();

        if ($latestRules !== null) {
            $chunks = array_merge($chunks, $chunker->chunkRulesText($latestRules));
        }

        foreach (ErrataBulletin::all() as $bulletin) {
            $chunks = array_merge($chunks, $chunker->chunkErrataBulletin($bulletin));
        }

        return array_merge($chunks, $chunker->chunkValidatedNotes());
    }

    /**
     * @param  array<int, string>  $touchedSourceTypes
     * @return Collection<int, KbDocument>
     */
    private function staleRowsOutsideTouchedTypes(array $touchedSourceTypes): Collection
    {
        $model = (string) config('enrichment.embedding.model');

        $query = KbDocument::query();

        if ($touchedSourceTypes !== []) {
            $query->whereNotIn('source_type', $touchedSourceTypes);
        }

        return $query->where(function ($q) use ($model): void {
            $q->where('embedding_model', '!=', $model)->orWhereNull('embedding');
        })->get();
    }

    /**
     * Dispatches EmbedKbChunks in rate-limited slices on their own queue and
     * blocks until the batch finishes, so this chain link doesn't complete —
     * and the enrich:run chain doesn't advance to the next step — before the
     * KB is actually populated. The bound wait guards against a killed
     * worker looping forever; the caller's superseded rows are only deleted
     * once the batch is confirmed to have finished without failures, so a
     * timeout or a partial failure leaves old content in place (possibly
     * duplicated once a later run succeeds) rather than losing it.
     *
     * @param  list<array{source_type: string, source_ref: ?string, content: string, trust_status: string, effective_date: ?string}>  $chunks
     * @param  list<string>  $idsToRetire
     */
    private function embedAndRetire(array $chunks, array $idsToRetire): void
    {
        if ($chunks === []) {
            return;
        }

        $batchSize = max(1, (int) config('enrichment.embedding.batch_size'));

        $jobs = array_map(
            fn (array $slice) => new EmbedKbChunks($this->context, $slice),
            array_chunk($chunks, $batchSize),
        );

        $batch = Bus::batch($jobs)->name('enrichment:kb-embed')->allowFailures()->dispatch();

        $deadline = time() + max(1, (int) config('enrichment.embedding.max_wait_seconds'));

        while (! $batch->fresh()->finished() && time() < $deadline) {
            usleep(100_000);
        }

        $batch = $batch->fresh();

        if (! $batch->finished()) {
            Log::warning('enrichment.kb_embed_timed_out', ['step' => $this->stepKey(), 'batch_id' => $batch->id]);

            return;
        }

        if ($batch->hasFailures()) {
            Log::warning('enrichment.kb_embed_partial_failure', [
                'step' => $this->stepKey(),
                'batch_id' => $batch->id,
                'failed_jobs' => $batch->failedJobIds,
            ]);

            return;
        }

        if ($idsToRetire !== []) {
            KbDocument::whereIn('id', $idsToRetire)->delete();
        }
    }
}
