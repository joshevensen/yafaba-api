<?php

namespace Tests\Feature;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Jobs\Enrichment\BuildKnowledgeBase;
use App\Jobs\Enrichment\GenerateCardExplainer;
use App\Jobs\Enrichment\TagCardCombos;
use App\Jobs\Enrichment\TagCardSynergies;
use App\Models\Card;
use App\Models\CardExplainer;
use App\Models\ComboPair;
use App\Models\ErrataBulletin;
use App\Models\KbDocument;
use App\Models\RulesTextVersion;
use App\Models\SynergyTag;
use App\Services\Enrichment\CardExplainerPromptBuilder;
use App\Services\Enrichment\ComboCandidateRetriever;
use App\Services\Enrichment\ComboTaggingPromptBuilder;
use App\Services\Enrichment\KbChunker;
use App\Services\Enrichment\KbRetriever;
use App\Services\EnrichmentRunContext;
use App\Services\Llm\LlmTransportFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakePsr18Client;
use Tests\TestCase;

class EnrichmentJobIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{message: string, context: array<string, mixed>}> */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->loggedMessages = [];

        Log::listen(function ($event): void {
            $this->loggedMessages[] = [
                'message' => $event->message,
                'context' => $event->context,
            ];
        });
    }

    /**
     * @return array<int, array{message: string, context: array<string, mixed>}>
     */
    private function loggedWithMessage(string $message): array
    {
        return array_values(array_filter(
            $this->loggedMessages,
            fn (array $entry): bool => $entry['message'] === $message,
        ));
    }

    private function context(array $overrides = []): EnrichmentRunContext
    {
        return new EnrichmentRunContext(
            pipelineRunId: $overrides['pipelineRunId'] ?? null,
            steps: $overrides['steps'] ?? ['kb', 'explainer', 'combo', 'synergy'],
            fresh: $overrides['fresh'] ?? false,
            dryRun: $overrides['dryRun'] ?? false,
            cardId: $overrides['cardId'] ?? null,
            via: $overrides['via'] ?? 'api',
            triggeredBy: $overrides['triggeredBy'] ?? 'manual',
            promptVersion: $overrides['promptVersion'] ?? 'v0',
        );
    }

    private function handleExplainerJob(GenerateCardExplainer $job): void
    {
        $job->handle(app(KbRetriever::class), app(CardExplainerPromptBuilder::class), app(LlmTransportFactory::class));
    }

    private function handleComboJob(TagCardCombos $job): void
    {
        $job->handle(app(ComboCandidateRetriever::class), app(KbRetriever::class), app(ComboTaggingPromptBuilder::class), app(LlmTransportFactory::class));
    }

    private function bindFakeAnthropicClient(): void
    {
        $body = [
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'emit_result', 'input' => ['explainer_text' => 'Because…', 'cited_rules' => ['1.2.3']]]],
            'stop_reason' => 'tool_use',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ];

        $this->app->instance(Client::class, new Client(
            apiKey: 'test-anthropic-key',
            requestOptions: RequestOptions::with(transporter: new FakePsr18Client([$body])),
        ));
    }

    public function test_generate_card_explainer_skips_when_up_to_date(): void
    {
        $card = Card::factory()->create(['updated_at' => now()->subDay()]);
        CardExplainer::factory()->create(['card_id' => $card->id, 'generated_at' => now()]);

        $this->handleExplainerJob(new GenerateCardExplainer($this->context(['cardId' => $card->id]), $card->id));

        $this->assertCount(1, $this->loggedWithMessage('enrichment.skipped'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.explainer_generated'));
    }

    public function test_generate_card_explainer_does_not_skip_when_fresh(): void
    {
        $card = Card::factory()->create(['updated_at' => now()->subDay(), 'functional_text' => 'Deal 1 damage.']);
        CardExplainer::factory()->create(['card_id' => $card->id, 'generated_at' => now()]);
        $this->bindFakeAnthropicClient();

        $this->handleExplainerJob(new GenerateCardExplainer($this->context(['cardId' => $card->id, 'fresh' => true]), $card->id));

        $this->assertCount(1, $this->loggedWithMessage('enrichment.explainer_generated'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.skipped'));
    }

    public function test_generate_card_explainer_respects_dry_run(): void
    {
        $card = Card::factory()->create();

        $this->handleExplainerJob(new GenerateCardExplainer($this->context(['cardId' => $card->id, 'dryRun' => true]), $card->id));

        $this->assertCount(1, $this->loggedWithMessage('enrichment.dry_run'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.explainer_generated'));
        $this->assertDatabaseCount('card_explainers', 0);
    }

    public function test_tag_card_combos_skips_when_up_to_date(): void
    {
        $card = Card::factory()->create(['source_hash' => 'hash-1', 'updated_at' => now()->subDay()]);
        ComboPair::factory()->create([
            'card_id_a' => $card->id,
            'source_hash' => 'hash-1',
            'prompt_version' => 'v0',
            'generated_at' => now(),
        ]);

        $this->handleComboJob(new TagCardCombos($this->context(['cardId' => $card->id]), $card->id));

        $this->assertCount(1, $this->loggedWithMessage('enrichment.skipped'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.combos_tagged'));
    }

    public function test_tag_card_combos_does_not_skip_when_fresh(): void
    {
        $card = Card::factory()->create(['source_hash' => 'hash-1', 'updated_at' => now()->subDay()]);
        ComboPair::factory()->create([
            'card_id_a' => $card->id,
            'source_hash' => 'hash-1',
            'prompt_version' => 'v0',
            'generated_at' => now(),
        ]);

        // No class/talent is attached to the card, so the real implementation
        // will find no combo candidates and hit the no_candidates skip path
        // before ever calling the LLM or Voyage - no HTTP faking required.
        // What we're proving here is that isUpToDate() was bypassed because
        // of `fresh`, not that a combo was actually written.
        $this->handleComboJob(new TagCardCombos($this->context(['cardId' => $card->id, 'fresh' => true]), $card->id));

        $skipped = $this->loggedWithMessage('enrichment.skipped');
        $this->assertCount(1, $skipped);
        $this->assertSame('no_candidates', $skipped[0]['context']['reason']);
    }

    public function test_tag_card_combos_does_not_skip_when_card_source_hash_changed(): void
    {
        $card = Card::factory()->create(['source_hash' => 'hash-2', 'updated_at' => now()->subDay()]);
        ComboPair::factory()->create([
            'card_id_a' => $card->id,
            'source_hash' => 'hash-1',
            'prompt_version' => 'v0',
            'generated_at' => now(),
        ]);

        // No class/talent is attached to the card, so the real implementation
        // will find no combo candidates and hit the no_candidates skip path.
        // This proves isUpToDate() returned false (source_hash mismatch)
        // rather than short-circuiting on the up_to_date reason.
        $this->handleComboJob(new TagCardCombos($this->context(['cardId' => $card->id]), $card->id));

        $skipped = $this->loggedWithMessage('enrichment.skipped');
        $this->assertCount(1, $skipped);
        $this->assertSame('no_candidates', $skipped[0]['context']['reason']);
    }

    public function test_tag_card_combos_respects_dry_run(): void
    {
        $card = Card::factory()->create();

        $countBefore = ComboPair::count();

        $this->handleComboJob(new TagCardCombos($this->context(['cardId' => $card->id, 'dryRun' => true]), $card->id));

        $this->assertCount(1, $this->loggedWithMessage('enrichment.dry_run'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.combos_tagged'));
        $this->assertSame($countBefore, ComboPair::count());
    }

    public function test_tag_card_synergies_skips_when_up_to_date(): void
    {
        $card = Card::factory()->create();
        $tag = SynergyTag::factory()->create();
        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $tag->id,
            'status' => 'draft',
        ]);

        (new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id))->handle();

        $this->assertCount(1, $this->loggedWithMessage('enrichment.skipped'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.stub'));
    }

    public function test_tag_card_synergies_does_not_skip_when_fresh(): void
    {
        $card = Card::factory()->create();
        $tag = SynergyTag::factory()->create();
        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $tag->id,
            'status' => 'draft',
        ]);

        (new TagCardSynergies($this->context(['cardId' => $card->id, 'fresh' => true]), $card->id))->handle();

        $this->assertCount(1, $this->loggedWithMessage('enrichment.stub'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.skipped'));
    }

    public function test_tag_card_synergies_respects_dry_run(): void
    {
        $card = Card::factory()->create();

        (new TagCardSynergies($this->context(['cardId' => $card->id, 'dryRun' => true]), $card->id))->handle();

        $this->assertCount(1, $this->loggedWithMessage('enrichment.dry_run'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.stub'));
        $this->assertDatabaseCount('card_synergy_tags', 0);
    }

    public function test_build_knowledge_base_skips_when_up_to_date(): void
    {
        Http::preventStrayRequests();

        RulesTextVersion::factory()->create(['fetched_at' => now()->subDay()]);
        ErrataBulletin::factory()->create(['cached_at' => now()->subDay()]);
        KbDocument::factory()->embedded()->create(['created_at' => now(), 'embedding_model' => config('enrichment.embedding.model')]);

        (new BuildKnowledgeBase($this->context()))->handle(app(KbChunker::class));

        $this->assertCount(1, $this->loggedWithMessage('enrichment.skipped'));
        Http::assertNothingSent();
    }

    public function test_build_knowledge_base_does_not_skip_when_fresh(): void
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

        RulesTextVersion::factory()->create(['fetched_at' => now()->subDay(), 'full_text' => "1.0 Rule one is a rule about something long enough to survive chunk merging thresholds in tests.\n2.0 Rule two is another rule long enough to survive merging thresholds as well, for good measure."]);
        ErrataBulletin::factory()->create(['cached_at' => now()->subDay()]);
        KbDocument::factory()->create(['created_at' => now()]);

        (new BuildKnowledgeBase($this->context(['fresh' => true])))->handle(app(KbChunker::class));

        $this->assertCount(0, $this->loggedWithMessage('enrichment.skipped'));
        $this->assertGreaterThan(0, KbDocument::where('embedding_model', config('enrichment.embedding.model'))->count());
    }

    public function test_build_knowledge_base_respects_dry_run(): void
    {
        Http::preventStrayRequests();

        $countBefore = KbDocument::count();

        (new BuildKnowledgeBase($this->context(['dryRun' => true])))->handle(app(KbChunker::class));

        $this->assertCount(1, $this->loggedWithMessage('enrichment.dry_run'));
        $this->assertSame($countBefore, KbDocument::count());
        Http::assertNothingSent();
    }

    public function test_tries_and_backoff_come_from_config(): void
    {
        $card = Card::factory()->create();

        $job = new GenerateCardExplainer($this->context(['cardId' => $card->id]), $card->id);

        $this->assertSame(config('enrichment.job.tries'), $job->tries);
        $this->assertSame(config('enrichment.job.backoff'), $job->backoff());
    }

    public function test_llm_job_stubs_are_rate_limited(): void
    {
        $card = Card::factory()->create();

        $explainerJob = new GenerateCardExplainer($this->context(['cardId' => $card->id]), $card->id);
        $comboJob = new TagCardCombos($this->context(['cardId' => $card->id]), $card->id);
        $synergyJob = new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id);

        foreach ([$explainerJob, $comboJob, $synergyJob] as $job) {
            $middleware = $job->middleware();
            $this->assertCount(1, $middleware);
            $this->assertInstanceOf(RateLimited::class, $middleware[0]);
        }
    }
}
