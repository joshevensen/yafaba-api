<?php

namespace Tests\Feature;

use App\Jobs\Enrichment\BuildKnowledgeBase;
use App\Jobs\Enrichment\EmbedKbChunks;
use App\Models\Card;
use App\Models\CardExplainer;
use App\Models\ErrataBulletin;
use App\Models\KbDocument;
use App\Models\RulesTextVersion;
use App\Services\Enrichment\KbChunker;
use App\Services\EnrichmentRunContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BuildKnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'enrichment.embedding.min_chunk_chars' => 0,
            'enrichment.embedding.max_chunk_chars' => 100000,
        ]);
    }

    private function context(array $overrides = []): EnrichmentRunContext
    {
        return new EnrichmentRunContext(
            pipelineRunId: $overrides['pipelineRunId'] ?? null,
            steps: $overrides['steps'] ?? ['kb'],
            fresh: $overrides['fresh'] ?? false,
            dryRun: $overrides['dryRun'] ?? false,
            cardId: $overrides['cardId'] ?? null,
            via: $overrides['via'] ?? 'api',
            triggeredBy: $overrides['triggeredBy'] ?? 'manual',
            promptVersion: $overrides['promptVersion'] ?? 'v0',
        );
    }

    private function fakeVoyageDynamic(): void
    {
        Http::fake([
            '*' => function ($request) {
                $input = $request->data()['input'];

                return Http::response(['data' => array_map(
                    fn (int $index): array => ['index' => $index, 'embedding' => array_fill(0, 1024, 0.1)],
                    array_keys($input),
                )]);
            },
        ]);
    }

    private function runJob(EnrichmentRunContext $context): void
    {
        (new BuildKnowledgeBase($context))->handle(app(KbChunker::class));
    }

    public function test_rows_are_written_with_expected_columns(): void
    {
        $this->fakeVoyageDynamic();

        RulesTextVersion::factory()->create(['full_text' => '1.0 Timing and priority rules covering the full stack in extensive detail for testing purposes here.']);
        ErrataBulletin::factory()->create();

        $this->runJob($this->context());

        $document = KbDocument::where('source_type', KbDocument::SOURCE_CR_RULES)->firstOrFail();

        $this->assertSame('voyage-3.5', $document->embedding_model);
        $this->assertSame(KbDocument::TRUST_GROUND_TRUTH, $document->trust_status);
        $this->assertSame(1, $document->version);
        $this->assertMatchesRegularExpression('/^\[-?\d/', $document->embedding);
        $this->assertSame(1023, substr_count($document->embedding, ','));
    }

    public function test_draft_notes_never_enter_the_kb(): void
    {
        $this->fakeVoyageDynamic();

        $validatedCard = Card::factory()->create();
        $draftCard = Card::factory()->create();

        CardExplainer::factory()->create(['card_id' => $validatedCard->id, 'status' => 'validated', 'explainer_text' => 'Validated explainer content.']);
        CardExplainer::factory()->create(['card_id' => $draftCard->id, 'status' => 'draft', 'explainer_text' => 'Draft explainer content.']);

        $this->runJob($this->context());

        $this->assertDatabaseHas('kb_documents', ['content' => 'Validated explainer content.']);
        $this->assertDatabaseMissing('kb_documents', ['content' => 'Draft explainer content.']);
    }

    public function test_dry_run_issues_no_http_requests_and_writes_nothing(): void
    {
        Http::preventStrayRequests();

        RulesTextVersion::factory()->create();
        ErrataBulletin::factory()->create();

        $countBefore = KbDocument::count();

        $this->runJob($this->context(['dryRun' => true]));

        $this->assertSame($countBefore, KbDocument::count());
        Http::assertNothingSent();
    }

    public function test_rerunning_without_fresh_after_a_successful_build_does_not_duplicate_rows(): void
    {
        $this->fakeVoyageDynamic();

        RulesTextVersion::factory()->create(['full_text' => '1.0 Rule text long enough to survive chunk merging for this scenario here.', 'fetched_at' => now()->subDay()]);
        ErrataBulletin::factory()->create(['cached_at' => now()->subDay()]);

        $this->runJob($this->context());
        $countAfterFirstRun = KbDocument::count();
        $this->assertGreaterThan(0, $countAfterFirstRun);

        // isUpToDate() now finds a kb_documents row newer than the sources, so this second
        // run should be skipped entirely rather than re-chunking and re-inserting.
        $this->runJob($this->context());

        $this->assertSame($countAfterFirstRun, KbDocument::count());
    }

    public function test_fresh_rebuild_deletes_prior_rows_per_source_type_and_reinserts_at_version_one(): void
    {
        $this->fakeVoyageDynamic();

        RulesTextVersion::factory()->create(['full_text' => '1.0 Rule text long enough to survive chunk merging for this scenario here.']);
        ErrataBulletin::factory()->create();

        $this->runJob($this->context(['fresh' => true]));
        $countAfterFirstRun = KbDocument::where('source_type', KbDocument::SOURCE_CR_RULES)->count();
        $this->assertGreaterThan(0, $countAfterFirstRun);

        $this->runJob($this->context(['fresh' => true]));
        $countAfterSecondRun = KbDocument::where('source_type', KbDocument::SOURCE_CR_RULES)->count();

        $this->assertSame($countAfterFirstRun, $countAfterSecondRun);
        $this->assertTrue(KbDocument::where('source_type', KbDocument::SOURCE_CR_RULES)->pluck('version')->every(fn (int $v): bool => $v === 1));
    }

    public function test_changing_the_embedding_model_re_embeds_stale_rows_without_matching_fresh_content(): void
    {
        Http::fake([
            '*' => function ($request) {
                $input = $request->data()['input'];

                return Http::response(['data' => array_map(
                    fn (int $index): array => ['index' => $index, 'embedding' => array_fill(0, 1024, 0.9)],
                    array_keys($input),
                )]);
            },
        ]);

        $stale = KbDocument::factory()->embedded()->create([
            'source_type' => 'combo',
            'embedding_model' => 'voyage-3',
        ]);

        config(['enrichment.embedding.model' => 'voyage-3.5']);

        $this->runJob($this->context());

        $refreshed = KbDocument::where('source_ref', $stale->source_ref)->where('source_type', 'combo')->firstOrFail();

        $this->assertSame('voyage-3.5', $refreshed->embedding_model);
        $this->assertSame($stale->content, $refreshed->content);
        $this->assertNotSame($stale->embedding, $refreshed->embedding);
    }

    public function test_re_embed_sweep_also_catches_rows_with_a_null_embedding(): void
    {
        $this->fakeVoyageDynamic();

        config(['enrichment.embedding.model' => 'voyage-3.5']);

        $orphan = KbDocument::factory()->create([
            'source_type' => 'combo',
            'embedding_model' => 'voyage-3.5',
            'embedding' => null,
        ]);

        $this->runJob($this->context());

        $refreshed = KbDocument::where('source_ref', $orphan->source_ref)->where('source_type', 'combo')->firstOrFail();

        $this->assertNotNull($refreshed->embedding);
    }

    public function test_embedding_work_is_fanned_out_into_rate_limited_jobs(): void
    {
        config(['enrichment.embedding.batch_size' => 1]);

        $sent = 0;
        Http::fake([
            '*' => function ($request) use (&$sent) {
                $sent++;
                $input = $request->data()['input'];

                return Http::response(['data' => array_map(
                    fn (int $index): array => ['index' => $index, 'embedding' => array_fill(0, 1024, 0.1)],
                    array_keys($input),
                )]);
            },
        ]);

        RulesTextVersion::factory()->create(['full_text' => "1.0 Timing and priority rules in detail here for this scenario testing.\n2.0 Combat and damage assignment rules in detail here for this scenario too."]);
        ErrataBulletin::factory()->create();

        $this->runJob($this->context());

        // batch_size=1 forces one Voyage request per chunk, proving the work is
        // fanned out into multiple rate-limited requests rather than a single
        // inline call covering every chunk.
        $this->assertGreaterThan(1, $sent);

        $job = new EmbedKbChunks($this->context(), []);
        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(RateLimited::class, $middleware[0]);
    }

    public function test_embed_kb_chunks_runs_on_a_dedicated_queue_distinct_from_build_knowledge_base(): void
    {
        $embedJob = new EmbedKbChunks($this->context(), []);
        $buildJob = new BuildKnowledgeBase($this->context());

        $this->assertSame(config('enrichment.embedding.queue'), $embedJob->queue);
        $this->assertNotSame($buildJob->queue, $embedJob->queue);
    }

    public function test_a_failed_embed_slice_does_not_delete_the_rows_it_would_have_replaced(): void
    {
        $existingFirst = KbDocument::factory()->embedded()->create([
            'source_type' => KbDocument::SOURCE_CR_RULES,
            'source_ref' => '1.0',
            'content' => 'stale first section content',
        ]);
        $existingSecond = KbDocument::factory()->embedded()->create([
            'source_type' => KbDocument::SOURCE_CR_RULES,
            'source_ref' => '2.0',
            'content' => 'stale second section content',
        ]);

        config(['enrichment.embedding.batch_size' => 1]);

        Http::fake([
            '*' => function ($request) {
                $input = $request->data()['input'];

                if (str_contains($input[0], 'Combat and damage')) {
                    return Http::response('voyage outage', 500);
                }

                return Http::response(['data' => array_map(
                    fn (int $index): array => ['index' => $index, 'embedding' => array_fill(0, 1024, 0.1)],
                    array_keys($input),
                )]);
            },
        ]);

        RulesTextVersion::factory()->create(['full_text' => "1.0 Timing and priority rules in detail here for this scenario testing.\n2.0 Combat and damage assignment rules in detail here for this scenario too."]);
        ErrataBulletin::factory()->create();

        $this->runJob($this->context());

        // The failing slice means the batch has failures, so BuildKnowledgeBase must not
        // have deleted either pre-existing row — losing "1.0"'s replaced content would be
        // fine (it re-embedded successfully) but losing "2.0" (whose replacement failed)
        // would be silent, permanent data loss.
        $this->assertDatabaseHas('kb_documents', ['id' => $existingFirst->id]);
        $this->assertDatabaseHas('kb_documents', ['id' => $existingSecond->id]);
    }
}
