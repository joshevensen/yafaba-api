<?php

namespace Tests\Feature;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Jobs\Enrichment\GenerateCardExplainer;
use App\Models\Card;
use App\Models\CardExplainer;
use App\Models\ErrataBulletin;
use App\Models\KbDocument;
use App\Models\Keyword;
use App\Services\Enrichment\CardExplainerPromptBuilder;
use App\Services\Enrichment\KbRetriever;
use App\Services\EnrichmentRunContext;
use App\Services\Llm\LlmTransportFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakePsr18Client;
use Tests\TestCase;

class GenerateCardExplainerTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{message: string, context: array<string, mixed>}> */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->loggedMessages = [];

        Log::listen(function ($event): void {
            $this->loggedMessages[] = ['message' => $event->message, 'context' => $event->context];
        });

        config(['enrichment.embedding.dimensions' => 3]);
    }

    private function context(array $overrides = []): EnrichmentRunContext
    {
        return new EnrichmentRunContext(
            pipelineRunId: $overrides['pipelineRunId'] ?? null,
            steps: ['explainer'],
            fresh: $overrides['fresh'] ?? false,
            dryRun: $overrides['dryRun'] ?? false,
            cardId: $overrides['cardId'] ?? null,
            via: $overrides['via'] ?? 'api',
            triggeredBy: 'manual',
            promptVersion: $overrides['promptVersion'] ?? 'v0',
        );
    }

    private function handleExplainerJob(GenerateCardExplainer $job): void
    {
        $job->handle(app(KbRetriever::class), app(CardExplainerPromptBuilder::class), app(LlmTransportFactory::class));
    }

    /**
     * @return array<int, array{explainer_text: string, cited_rules: list<string>}>
     */
    private function bindFakeAnthropicClient(array $payload = ['explainer_text' => 'Because…', 'cited_rules' => ['8.5.3b']]): FakePsr18Client
    {
        $body = [
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'emit_result', 'input' => $payload]],
            'stop_reason' => 'tool_use',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ];

        $fake = new FakePsr18Client([$body]);

        $this->app->instance(Client::class, new Client(
            apiKey: 'test-anthropic-key',
            requestOptions: RequestOptions::with(transporter: $fake),
        ));

        return $fake;
    }

    private function capturedPrompt(FakePsr18Client $fake): string
    {
        $body = json_decode((string) $fake->requests[0]->getBody(), true);

        return $body['messages'][0]['content'];
    }

    private function fakeVoyageEmbeddings(array $vector = [1, 0, 0]): void
    {
        Http::fake([
            '*' => Http::response(['data' => [['index' => 0, 'embedding' => $vector]]]),
        ]);
    }

    public function test_it_writes_a_draft_explainer_row(): void
    {
        $card = Card::factory()->create(['functional_text' => null]);
        $this->bindFakeAnthropicClient(['explainer_text' => 'Because…', 'cited_rules' => ['8.5.3b']]);

        $this->handleExplainerJob(new GenerateCardExplainer($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseHas('card_explainers', [
            'card_id' => $card->id,
            'status' => 'draft',
            'explainer_text' => 'Because…',
        ]);

        $explainer = CardExplainer::find($card->id);
        $this->assertSame(['8.5.3b'], $explainer->cited_rules);
        $this->assertNotNull($explainer->generated_at);
        $this->assertSame($card->source_hash, $explainer->source_hash);
        $this->assertSame('v0', $explainer->prompt_version);
    }

    public function test_it_grounds_the_prompt_on_card_keywords_kb_chunks_and_errata(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage to target hero.']);
        $keyword = Keyword::factory()->create(['name' => 'Dominate', 'explainer' => 'Dominate glossary text.']);
        $card->keywords()->attach($keyword->id);
        KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_GROUND_TRUTH, 'embedding' => '[1,0,0]', 'source_ref' => '8.5.3b', 'content' => 'CR excerpt content.']);
        ErrataBulletin::factory()->create(['bulletin_number' => 'B-42', 'content' => 'Errata content.', 'affected_card_ids' => [$card->id]]);

        $this->fakeVoyageEmbeddings();
        $fake = $this->bindFakeAnthropicClient();

        $this->handleExplainerJob(new GenerateCardExplainer($this->context(['cardId' => $card->id]), $card->id));

        $prompt = $this->capturedPrompt($fake);

        $this->assertStringContainsString('Deal 1 damage to target hero.', $prompt);
        $this->assertStringContainsString('Dominate glossary text.', $prompt);
        $this->assertStringContainsString('CR excerpt content.', $prompt);
        $this->assertStringContainsString('B-42', $prompt);
        $this->assertStringContainsString('Errata content.', $prompt);
    }

    public function test_it_excludes_errata_that_do_not_affect_the_card(): void
    {
        $card = Card::factory()->create(['functional_text' => null]);
        $otherCard = Card::factory()->create();
        ErrataBulletin::factory()->create(['bulletin_number' => 'B-AFFECTS', 'content' => 'Affecting errata.', 'affected_card_ids' => [$card->id]]);
        ErrataBulletin::factory()->create(['bulletin_number' => 'B-OTHER', 'content' => 'Unrelated errata.', 'affected_card_ids' => [$otherCard->id]]);

        $fake = $this->bindFakeAnthropicClient();

        $this->handleExplainerJob(new GenerateCardExplainer($this->context(['cardId' => $card->id]), $card->id));

        $prompt = $this->capturedPrompt($fake);

        $this->assertStringContainsString('B-AFFECTS', $prompt);
        $this->assertStringNotContainsString('B-OTHER', $prompt);
    }

    public function test_it_overwrites_an_existing_explainer_and_clears_validation(): void
    {
        $card = Card::factory()->create(['functional_text' => null, 'source_hash' => 'old-hash']);
        CardExplainer::factory()->validated()->create(['card_id' => $card->id, 'source_hash' => 'old-hash', 'prompt_version' => 'v0']);
        $this->bindFakeAnthropicClient();

        $this->handleExplainerJob(new GenerateCardExplainer($this->context(['cardId' => $card->id, 'fresh' => true]), $card->id));

        $this->assertDatabaseCount('card_explainers', 1);
        $explainer = CardExplainer::find($card->id);
        $this->assertSame('draft', $explainer->status);
        $this->assertNull($explainer->validated_at);
    }

    public function test_it_skips_when_source_hash_and_prompt_version_are_unchanged(): void
    {
        $card = Card::factory()->create(['functional_text' => null, 'source_hash' => 'hash-1', 'updated_at' => now()->subDay()]);
        CardExplainer::factory()->create(['card_id' => $card->id, 'source_hash' => 'hash-1', 'prompt_version' => 'v0', 'generated_at' => now()]);

        $this->handleExplainerJob(new GenerateCardExplainer($this->context(['cardId' => $card->id, 'promptVersion' => 'v0']), $card->id));

        $this->assertCount(1, array_filter($this->loggedMessages, fn (array $e): bool => $e['message'] === 'enrichment.skipped'));
    }

    public function test_it_regenerates_when_the_card_source_hash_changed(): void
    {
        $card = Card::factory()->create(['functional_text' => null, 'source_hash' => 'hash-2', 'updated_at' => now()->subDay()]);
        CardExplainer::factory()->create(['card_id' => $card->id, 'source_hash' => 'hash-1', 'prompt_version' => 'v0', 'generated_at' => now()]);
        $this->bindFakeAnthropicClient();

        $this->handleExplainerJob(new GenerateCardExplainer($this->context(['cardId' => $card->id, 'promptVersion' => 'v0']), $card->id));

        $this->assertSame('hash-2', CardExplainer::find($card->id)->source_hash);
    }

    public function test_it_regenerates_when_the_prompt_version_changed(): void
    {
        $card = Card::factory()->create(['functional_text' => null, 'updated_at' => now()->subDay()]);
        CardExplainer::factory()->create(['card_id' => $card->id, 'source_hash' => $card->source_hash, 'prompt_version' => 'v0', 'generated_at' => now()]);
        $this->bindFakeAnthropicClient();

        $this->handleExplainerJob(new GenerateCardExplainer($this->context(['cardId' => $card->id, 'promptVersion' => 'v1']), $card->id));

        $this->assertSame('v1', CardExplainer::find($card->id)->prompt_version);
    }

    public function test_it_makes_no_external_calls_on_dry_run(): void
    {
        $card = Card::factory()->create();
        Http::preventStrayRequests();
        $this->bindFakeAnthropicClient(); // queued but must never be dequeued

        $this->handleExplainerJob(new GenerateCardExplainer($this->context(['cardId' => $card->id, 'dryRun' => true]), $card->id));

        Http::assertNothingSent();
        $this->assertDatabaseCount('card_explainers', 0);
    }

    public function test_it_uses_the_configured_card_explainer_model(): void
    {
        config(['enrichment.models.card_explainer' => 'claude-test-model']);
        $card = Card::factory()->create(['functional_text' => null]);
        $fake = $this->bindFakeAnthropicClient();

        $this->handleExplainerJob(new GenerateCardExplainer($this->context(['cardId' => $card->id]), $card->id));

        $body = json_decode((string) $fake->requests[0]->getBody(), true);
        $this->assertSame('claude-test-model', $body['model']);
    }

    public function test_single_card_explainer_run_writes_a_draft_row(): void
    {
        $card = Card::factory()->create(['functional_text' => null]);
        $this->bindFakeAnthropicClient();

        $this->artisan('enrich:run', ['--card' => $card->id, '--only' => 'explainer'])->assertSuccessful();

        $this->assertDatabaseHas('card_explainers', ['card_id' => $card->id, 'status' => 'draft']);
    }
}
