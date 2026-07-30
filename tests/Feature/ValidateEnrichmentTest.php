<?php

namespace Tests\Feature;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Jobs\Enrichment\ValidateEnrichment;
use App\Models\Card;
use App\Models\CardExplainer;
use App\Models\ComboPair;
use App\Models\RulesTextVersion;
use App\Models\SynergyTag;
use App\Services\Enrichment\ComboSynergyPlausibilityAuditor;
use App\Services\Enrichment\ExplainerGroundingChecker;
use App\Services\Enrichment\ExplainerValidationPromptBuilder;
use App\Services\EnrichmentRunContext;
use App\Services\Llm\LlmTransportFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakePsr18Client;
use Tests\TestCase;

class ValidateEnrichmentTest extends TestCase
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
            steps: ['validate'],
            fresh: $overrides['fresh'] ?? false,
            dryRun: $overrides['dryRun'] ?? false,
            cardId: $overrides['cardId'] ?? null,
            via: $overrides['via'] ?? 'api',
            triggeredBy: 'manual',
            promptVersion: $overrides['promptVersion'] ?? 'v0',
        );
    }

    private function handleJob(ValidateEnrichment $job): void
    {
        $job->handle(
            app(ExplainerGroundingChecker::class),
            app(ExplainerValidationPromptBuilder::class),
            app(LlmTransportFactory::class),
            app(ComboSynergyPlausibilityAuditor::class),
        );
    }

    /**
     * @param  array{is_consistent: bool, confidence: float, reason: string}  $payload
     */
    private function bindFakeAnthropicClient(array $payload): FakePsr18Client
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

    private function fakeVoyageEmbeddings(array $vector = [1, 0, 0]): void
    {
        Http::fake([
            '*' => Http::response(['data' => [
                ['index' => 0, 'embedding' => $vector],
                ['index' => 1, 'embedding' => $vector],
            ]]),
        ]);
    }

    private function draftExplainer(array $overrides = []): CardExplainer
    {
        RulesTextVersion::factory()->create(['full_text' => "8.5.3b This is the rule text.\n"]);

        return CardExplainer::factory()->create(array_merge([
            'status' => 'draft',
            'explainer_text' => 'Because of rule 8.5.3b.',
            'cited_rules' => ['8.5.3b'],
        ], $overrides));
    }

    public function test_it_promotes_an_explainer_when_the_llm_verdict_is_consistent(): void
    {
        $explainer = $this->draftExplainer();
        $this->bindFakeAnthropicClient(['is_consistent' => true, 'confidence' => 0.92, 'reason' => 'Matches.']);

        $this->handleJob(new ValidateEnrichment($this->context()));

        $explainer->refresh();
        $this->assertSame('validated', $explainer->status);
        $this->assertNotNull($explainer->validated_at);
        $this->assertSame(0.92, $explainer->grounding_confidence);
        $this->assertNull($explainer->grounding_flag_reason);
    }

    public function test_it_leaves_an_explainer_draft_when_the_llm_verdict_is_inconsistent(): void
    {
        $explainer = $this->draftExplainer();
        $this->bindFakeAnthropicClient(['is_consistent' => false, 'confidence' => 0.4, 'reason' => 'Talks about an unrelated ability.']);

        $this->handleJob(new ValidateEnrichment($this->context()));

        $explainer->refresh();
        $this->assertSame('draft', $explainer->status);
        $this->assertNull($explainer->validated_at);
        $this->assertSame(0.4, $explainer->grounding_confidence);
        $this->assertSame('Talks about an unrelated ability.', $explainer->grounding_flag_reason);
    }

    public function test_a_deterministic_failure_flags_the_row_and_makes_no_llm_request(): void
    {
        RulesTextVersion::factory()->create(['full_text' => "8.5.3b This is the rule text.\n"]);
        $explainer = CardExplainer::factory()->create([
            'status' => 'draft',
            'explainer_text' => 'Because of rule 99.9.9.',
            'cited_rules' => ['99.9.9'],
        ]);
        $fake = $this->bindFakeAnthropicClient(['is_consistent' => true, 'confidence' => 0.9, 'reason' => '']);

        $this->handleJob(new ValidateEnrichment($this->context()));

        $explainer->refresh();
        $this->assertSame('draft', $explainer->status);
        $this->assertNull($explainer->validated_at);
        $this->assertSame(0.0, $explainer->grounding_confidence);
        $this->assertStringContainsString('99.9.9', (string) $explainer->grounding_flag_reason);
        $this->assertCount(0, $fake->requests);
    }

    public function test_it_uses_the_configured_explainer_validation_model(): void
    {
        config(['enrichment.models.explainer_validation' => 'claude-test-model']);
        $this->draftExplainer();
        $fake = $this->bindFakeAnthropicClient(['is_consistent' => true, 'confidence' => 0.9, 'reason' => '']);

        $this->handleJob(new ValidateEnrichment($this->context()));

        $body = json_decode((string) $fake->requests[0]->getBody(), true);
        $this->assertSame('claude-test-model', $body['model']);
    }

    public function test_it_logs_a_result_per_processed_explainer(): void
    {
        $this->draftExplainer();
        $this->bindFakeAnthropicClient(['is_consistent' => true, 'confidence' => 0.9, 'reason' => '']);

        $this->handleJob(new ValidateEnrichment($this->context()));

        $logged = array_filter($this->loggedMessages, fn (array $e): bool => $e['message'] === 'enrichment.explainer_validated');
        $this->assertCount(1, $logged);
        $this->assertSame('validated', array_values($logged)[0]['context']['result']);
    }

    public function test_dry_run_makes_no_writes_or_external_calls(): void
    {
        $explainer = $this->draftExplainer();
        Http::preventStrayRequests();
        $this->bindFakeAnthropicClient(['is_consistent' => true, 'confidence' => 0.9, 'reason' => '']);

        $this->handleJob(new ValidateEnrichment($this->context(['dryRun' => true])));

        Http::assertNothingSent();
        $explainer->refresh();
        $this->assertSame('draft', $explainer->status);
        $this->assertNull($explainer->grounding_confidence);
    }

    public function test_every_scorable_draft_combo_pair_gets_a_plausibility_score(): void
    {
        $combo = ComboPair::factory()->create([
            'status' => 'draft',
            'card_id_a' => Card::factory()->create(['functional_text' => 'Deal 1 damage.']),
            'card_id_b' => Card::factory()->create(['functional_text' => 'Draw a card.']),
        ]);
        $this->fakeVoyageEmbeddings();

        $this->handleJob(new ValidateEnrichment($this->context()));

        $this->assertNotNull($combo->fresh()->plausibility_score);
    }

    public function test_every_scorable_draft_synergy_tag_row_gets_a_plausibility_score(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $tag = SynergyTag::factory()->create(['description' => 'Damage synergy.']);
        DB::table('card_synergy_tags')->insert(['card_id' => $card->id, 'synergy_tag_id' => $tag->id, 'status' => 'draft']);
        $this->fakeVoyageEmbeddings();

        $this->handleJob(new ValidateEnrichment($this->context()));

        $score = DB::table('card_synergy_tags')->where('card_id', $card->id)->where('synergy_tag_id', $tag->id)->value('plausibility_score');
        $this->assertNotNull($score);
    }

    public function test_combo_and_synergy_rows_stay_draft_without_a_human_signoff(): void
    {
        $combo = ComboPair::factory()->create([
            'status' => 'draft',
            'card_id_a' => Card::factory()->create(['functional_text' => 'Deal 1 damage.']),
            'card_id_b' => Card::factory()->create(['functional_text' => 'Draw a card.']),
        ]);
        $this->fakeVoyageEmbeddings();

        $this->handleJob(new ValidateEnrichment($this->context()));

        $this->assertSame('draft', $combo->fresh()->status);
    }

    public function test_a_combo_pair_with_human_signoff_promotes_on_the_same_run(): void
    {
        $combo = ComboPair::factory()->create([
            'status' => 'draft',
            'human_signoff_at' => now(),
            'card_id_a' => Card::factory()->create(['functional_text' => 'Deal 1 damage.']),
            'card_id_b' => Card::factory()->create(['functional_text' => 'Draw a card.']),
        ]);
        $this->fakeVoyageEmbeddings();

        $this->handleJob(new ValidateEnrichment($this->context()));

        $combo->refresh();
        $this->assertSame('validated', $combo->status);
        $this->assertNotNull($combo->plausibility_score);
    }

    public function test_a_synergy_tag_row_with_human_signoff_promotes_on_the_same_run(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $tag = SynergyTag::factory()->create(['description' => 'Damage synergy.']);
        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $tag->id,
            'status' => 'draft',
            'human_signoff_at' => now(),
        ]);
        $this->fakeVoyageEmbeddings();

        $this->handleJob(new ValidateEnrichment($this->context()));

        $row = DB::table('card_synergy_tags')->where('card_id', $card->id)->where('synergy_tag_id', $tag->id)->first();
        $this->assertSame('validated', $row->status);
        $this->assertNotNull($row->plausibility_score);
    }
}
