<?php

namespace Tests\Feature;

use App\Jobs\Enrichment\BuildKnowledgeBase;
use App\Jobs\Enrichment\FinalizeEnrichmentRun;
use App\Jobs\Enrichment\GenerateCardExplainer;
use App\Jobs\Enrichment\PrepareEnrichmentRun;
use App\Jobs\Enrichment\PublishEnrichment;
use App\Jobs\Enrichment\TagCardCombos;
use App\Jobs\Enrichment\TagCardSynergies;
use App\Jobs\Enrichment\ValidateEnrichment;
use App\Models\Card;
use App\Models\CardExplainer;
use App\Models\ComboPair;
use App\Models\KbDocument;
use App\Models\PipelineRun;
use App\Services\EnrichmentRunContext;
use App\Services\PipelineRunRecorder;
use Illuminate\Bus\ChainedBatch;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule as ScheduleRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\ThrowingTestJob;
use Tests\TestCase;

class EnrichRunCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, string>
     */
    private function dispatchedChainClasses(): array
    {
        $head = Bus::dispatched(PrepareEnrichmentRun::class)->first();

        if ($head === null) {
            return [];
        }

        return array_map(fn (string $job): string => get_class(unserialize($job)), $head->chained);
    }

    public function test_help_lists_all_options(): void
    {
        $this->artisan('enrich:run', ['--help' => true])
            ->assertExitCode(Command::SUCCESS)
            ->expectsOutputToContain('--only')
            ->expectsOutputToContain('--fresh')
            ->expectsOutputToContain('--dry-run')
            ->expectsOutputToContain('--card')
            ->expectsOutputToContain('--via')
            ->expectsOutputToContain('--triggered-by');
    }

    public function test_gated_no_op_exits_success(): void
    {
        Bus::fake();

        PipelineRun::factory()->enrichment()->create(['finished_at' => now()->subHour()]);

        $this->artisan('enrich:run', ['--triggered-by' => 'scheduled'])->assertExitCode(Command::SUCCESS);

        Bus::assertNothingDispatched();
    }

    public function test_dispatches_full_chain_in_order_with_no_only(): void
    {
        Bus::fake();

        Card::factory()->count(2)->create();

        $this->artisan('enrich:run')->assertExitCode(Command::SUCCESS);

        $chain = $this->dispatchedChainClasses();

        $this->assertSame([
            BuildKnowledgeBase::class,
            ChainedBatch::class,
            ChainedBatch::class,
            ChainedBatch::class,
            ValidateEnrichment::class,
            PublishEnrichment::class,
            FinalizeEnrichmentRun::class,
        ], $chain);
    }

    public function test_only_filters_the_chain(): void
    {
        Bus::fake();

        Card::factory()->count(2)->create();

        $this->artisan('enrich:run', ['--only' => 'explainer,publish'])->assertExitCode(Command::SUCCESS);

        $chain = $this->dispatchedChainClasses();

        $this->assertSame([ChainedBatch::class, PublishEnrichment::class, FinalizeEnrichmentRun::class], $chain);
    }

    public function test_only_reorders_steps_into_config_order(): void
    {
        Bus::fake();

        Card::factory()->count(2)->create();

        $this->artisan('enrich:run', ['--only' => 'publish,kb'])->assertExitCode(Command::SUCCESS);

        $chain = $this->dispatchedChainClasses();

        $this->assertSame([BuildKnowledgeBase::class, PublishEnrichment::class, FinalizeEnrichmentRun::class], $chain);
    }

    public function test_unknown_only_key_fails_without_dispatching(): void
    {
        Bus::fake();

        $exitCode = $this->artisan('enrich:run', ['--only' => 'bogus'])->run();

        $this->assertSame(Command::FAILURE, $exitCode);
        Bus::assertNothingDispatched();
        $this->assertSame(0, PipelineRun::count());
    }

    public function test_fan_out_batches_have_one_job_per_card(): void
    {
        Bus::fake();

        Card::factory()->count(3)->create();

        $this->artisan('enrich:run', ['--only' => 'explainer'])->assertExitCode(Command::SUCCESS);

        $head = Bus::dispatched(PrepareEnrichmentRun::class)->first();
        $batch = unserialize($head->chained[0]);

        $this->assertInstanceOf(ChainedBatch::class, $batch);
        $this->assertSame(3, $batch->jobs->count());
        $this->assertTrue($batch->jobs->every(fn ($job) => $job instanceof GenerateCardExplainer));
    }

    public function test_empty_card_scope_dispatches_no_batch_and_still_succeeds(): void
    {
        Bus::fake();

        $card = Card::factory()->create();
        CardExplainer::factory()->for($card, 'card')->create(['generated_at' => now()]);

        $this->artisan('enrich:run', ['--only' => 'explainer'])->assertExitCode(Command::SUCCESS);

        $chain = $this->dispatchedChainClasses();

        $this->assertSame([FinalizeEnrichmentRun::class], $chain);
    }

    public function test_gate_blocks_scheduled_run_in_cooldown(): void
    {
        Bus::fake();
        Log::spy();

        PipelineRun::factory()->enrichment()->create(['finished_at' => now()->subHour()]);

        $exitCode = $this->artisan('enrich:run', ['--triggered-by' => 'scheduled'])->run();

        $this->assertSame(Command::SUCCESS, $exitCode);
        Bus::assertNothingDispatched();
        $this->assertSame(1, PipelineRun::count());

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'enrichment.gate_blocked' && $context['reason'] === 'cooldown')->atLeast()->once();
    }

    public function test_start_returning_null_warns_without_dispatch(): void
    {
        Bus::fake();

        Cache::lock('pipeline-run:enrichment', config('pipeline.lock_ttl_seconds'))->get();

        $exitCode = $this->artisan('enrich:run', ['--triggered-by' => 'manual'])->run();

        $this->assertSame(Command::SUCCESS, $exitCode);
        Bus::assertNothingDispatched();
        $this->assertSame(0, PipelineRun::count());
    }

    public function test_manual_trigger_bypasses_cooldown_but_yields_to_in_flight_run(): void
    {
        Bus::fake();

        PipelineRun::factory()->enrichment()->create(['finished_at' => now()->subHour()]);

        $this->artisan('enrich:run', ['--triggered-by' => 'manual'])->assertExitCode(Command::SUCCESS);

        Bus::assertDispatched(PrepareEnrichmentRun::class);

        Bus::fake();
        PipelineRun::query()->delete();
        PipelineRun::factory()->enrichment()->running()->create();

        $this->artisan('enrich:run', ['--triggered-by' => 'manual'])->assertExitCode(Command::SUCCESS);

        Bus::assertNothingDispatched();
    }

    public function test_finalize_marks_pipeline_run_success_with_counts(): void
    {
        $run = app(PipelineRunRecorder::class)->start(PipelineRun::PHASE_ENRICHMENT, 'enrich:run', 'manual');

        $context = new EnrichmentRunContext(
            pipelineRunId: $run->id,
            steps: ['explainer'],
            fresh: false,
            dryRun: false,
            cardId: null,
            via: 'api',
            triggeredBy: 'manual',
            promptVersion: 'v0',
            plannedCounts: ['explainer' => 2],
        );

        (new FinalizeEnrichmentRun($context))->handle(app(PipelineRunRecorder::class));

        $run->refresh();

        $this->assertSame(PipelineRun::STATUS_SUCCESS, $run->status);
        $this->assertFalse($run->is_running);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(2, $run->counts['explainer']);
    }

    public function test_chain_catch_marks_pipeline_run_failed(): void
    {
        $run = app(PipelineRunRecorder::class)->start(PipelineRun::PHASE_ENRICHMENT, 'enrich:run', 'manual');
        $runId = $run->id;

        // Mirrors EnrichRun::runPipeline()'s exact catch-callback pattern: a
        // static closure capturing only the run id, resolving the recorder
        // via the container — proving that pattern actually wires up through
        // a real Bus::chain()->catch() dispatch, not just PipelineRunRecorder
        // in isolation. The sync queue connection rethrows the job's
        // exception after invoking chain catch callbacks, so it's expected
        // here (not a test failure).
        try {
            Bus::chain([new ThrowingTestJob])
                ->catch(static function (\Throwable $e) use ($runId): void {
                    $run = PipelineRun::find($runId);

                    if ($run !== null) {
                        app(PipelineRunRecorder::class)->fail($run, $e->getMessage());
                    }
                })
                ->dispatch();
        } catch (\Throwable) {
            // Expected: SyncQueue rethrows after invoking chain catch callbacks.
        }

        $run->refresh();

        $this->assertSame(PipelineRun::STATUS_FAILED, $run->status);
        $this->assertSame('boom', $run->error);
        $this->assertFalse($run->is_running);
    }

    public function test_card_mode_runs_inline_creates_no_pipeline_run_and_no_batch(): void
    {
        Log::spy();

        $card = Card::factory()->create();

        $exitCode = $this->artisan('enrich:run', ['--card' => $card->id, '--only' => 'explainer'])->run();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(0, PipelineRun::count());
        $this->assertSame(0, DB::table('job_batches')->count());

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'enrichment.stub' && $context['step'] === 'explainer' && $context['card_id'] === $card->id)->atLeast()->once();
    }

    public function test_card_mode_with_unknown_card_fails(): void
    {
        Bus::fake();

        $exitCode = $this->artisan('enrich:run', ['--card' => (string) Str::uuid()])->run();

        $this->assertSame(Command::FAILURE, $exitCode);
        Bus::assertNothingDispatched();
    }

    public function test_dry_run_persists_nothing_and_records_dry_run_flag(): void
    {
        Card::factory()->count(2)->create();

        $explainersBefore = CardExplainer::count();
        $combosBefore = ComboPair::count();
        $synergiesBefore = DB::table('card_synergy_tags')->count();
        $kbBefore = KbDocument::count();

        $exitCode = $this->artisan('enrich:run', ['--dry-run' => true])->run();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame($explainersBefore, CardExplainer::count());
        $this->assertSame($combosBefore, ComboPair::count());
        $this->assertSame($synergiesBefore, DB::table('card_synergy_tags')->count());
        $this->assertSame($kbBefore, KbDocument::count());

        $run = PipelineRun::forPhase(PipelineRun::PHASE_ENRICHMENT)->firstOrFail();
        $this->assertTrue($run->counts['dry_run']);
    }

    public function test_every_job_declares_tries_and_backoff_from_config(): void
    {
        $context = new EnrichmentRunContext(null, ['explainer'], false, false, null, 'api', 'manual', 'v0');
        $cardId = (string) Str::uuid();

        $jobs = [
            new PrepareEnrichmentRun($context),
            new BuildKnowledgeBase($context),
            new GenerateCardExplainer($context, $cardId),
            new TagCardCombos($context, $cardId),
            new TagCardSynergies($context, $cardId),
            new ValidateEnrichment($context),
            new PublishEnrichment($context),
            new FinalizeEnrichmentRun($context),
        ];

        foreach ($jobs as $job) {
            $this->assertSame(config('enrichment.job.tries'), $job->tries, $job::class);
            $this->assertSame(config('enrichment.job.backoff'), $job->backoff(), $job::class);
        }
    }

    public function test_llm_and_embedding_job_stubs_use_rate_limited_middleware_and_limiters_resolve(): void
    {
        $context = new EnrichmentRunContext(null, ['explainer'], false, false, null, 'api', 'manual', 'v0');
        $cardId = (string) Str::uuid();

        $llmJobs = [
            new GenerateCardExplainer($context, $cardId),
            new TagCardCombos($context, $cardId),
            new TagCardSynergies($context, $cardId),
        ];

        foreach ($llmJobs as $job) {
            $middleware = $job->middleware();
            $this->assertCount(1, $middleware);
            $this->assertInstanceOf(RateLimited::class, $middleware[0]);
        }

        $kbMiddleware = (new BuildKnowledgeBase($context))->middleware();
        $this->assertCount(1, $kbMiddleware);
        $this->assertInstanceOf(RateLimited::class, $kbMiddleware[0]);

        $this->assertNotNull(RateLimiter::limiter('enrichment-llm'));
        $this->assertNotNull(RateLimiter::limiter('enrichment-embedding'));
    }

    public function test_jobs_are_queued_on_the_configured_queue(): void
    {
        Bus::fake();

        Card::factory()->create();

        $this->artisan('enrich:run', ['--only' => 'kb'])->assertExitCode(Command::SUCCESS);

        Bus::assertChained([
            PrepareEnrichmentRun::class,
            BuildKnowledgeBase::class,
            FinalizeEnrichmentRun::class,
        ]);

        $head = Bus::dispatched(PrepareEnrichmentRun::class)->first();
        $this->assertSame(config('enrichment.queue'), $head->chainQueue);
    }

    public function test_schedule_registers_a_single_weekly_entry_without_fresh_or_cli(): void
    {
        $events = app(ScheduleRunner::class)->events();

        $matching = collect($events)->filter(fn ($event) => str_contains($event->command ?? '', 'enrich:run'));

        $this->assertCount(1, $matching);

        $event = $matching->first();

        $this->assertSame('0 10 * * 1', $event->expression);
        $this->assertStringNotContainsString('--via=cli', $event->command);
        $this->assertStringNotContainsString('--fresh', $event->command);
    }
}
