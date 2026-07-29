<?php

namespace Tests\Feature;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Jobs\Enrichment\TagCardCombos;
use App\Models\Card;
use App\Models\CardClass;
use App\Models\ComboPair;
use App\Services\Enrichment\ComboCandidateRetriever;
use App\Services\Enrichment\ComboTaggingPromptBuilder;
use App\Services\Enrichment\KbRetriever;
use App\Services\EnrichmentPlanner;
use App\Services\EnrichmentRunContext;
use App\Services\Llm\LlmTransportFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakePsr18Client;
use Tests\TestCase;

class TagCardCombosTest extends TestCase
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
            steps: ['combo'],
            fresh: $overrides['fresh'] ?? false,
            dryRun: $overrides['dryRun'] ?? false,
            cardId: $overrides['cardId'] ?? null,
            via: $overrides['via'] ?? 'api',
            triggeredBy: 'manual',
            promptVersion: $overrides['promptVersion'] ?? 'v0',
        );
    }

    private function handleComboJob(TagCardCombos $job): void
    {
        $job->handle(
            app(ComboCandidateRetriever::class),
            app(KbRetriever::class),
            app(ComboTaggingPromptBuilder::class),
            app(LlmTransportFactory::class),
        );
    }

    /**
     * @param  list<array{card_id_b: string, description: string}>  $combos
     */
    private function bindFakeAnthropicClient(array $combos): FakePsr18Client
    {
        $body = [
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-4-5-20251001',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'emit_result', 'input' => ['combos' => $combos]]],
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
        Http::fake(function ($request) use ($vector) {
            $input = $request->data()['input'];
            $data = [];

            foreach ($input as $index => $text) {
                $data[] = ['index' => $index, 'embedding' => $vector];
            }

            return Http::response(['data' => $data]);
        });
    }

    /**
     * @return array{0: Card, 1: Card}
     */
    private function cardWithSharedClassPartner(): array
    {
        $class = CardClass::create(['name' => 'Warrior']);

        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $card->classes()->attach($class->id);

        $partner = Card::factory()->create(['functional_text' => 'Draw a card.']);
        $partner->classes()->attach($class->id);

        return [$card, $partner];
    }

    public function test_it_writes_draft_combo_pairs(): void
    {
        [$card, $partner] = $this->cardWithSharedClassPartner();
        $this->fakeVoyageEmbeddings();
        $this->bindFakeAnthropicClient([
            ['card_id_b' => $partner->id, 'description' => 'They combo well together.'],
        ]);

        $this->handleComboJob(new TagCardCombos($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseHas('combo_pairs', [
            'card_id_a' => $card->id,
            'card_id_b' => $partner->id,
            'status' => 'draft',
            'description' => 'They combo well together.',
        ]);

        $row = ComboPair::where('card_id_a', $card->id)->where('card_id_b', $partner->id)->first();
        $this->assertSame($card->source_hash, $row->source_hash);
        $this->assertSame('v0', $row->prompt_version);
        $this->assertNotNull($row->generated_at);
    }

    public function test_it_discards_proposals_for_cards_not_in_the_candidate_set(): void
    {
        [$card, $partner] = $this->cardWithSharedClassPartner();
        $strangerCard = Card::factory()->create();
        $this->fakeVoyageEmbeddings();
        $this->bindFakeAnthropicClient([
            ['card_id_b' => $strangerCard->id, 'description' => 'Not actually a candidate.'],
        ]);

        $this->handleComboJob(new TagCardCombos($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseMissing('combo_pairs', ['card_id_a' => $card->id, 'card_id_b' => $strangerCard->id]);
    }

    public function test_it_discards_self_pair_proposals(): void
    {
        [$card, $partner] = $this->cardWithSharedClassPartner();
        $this->fakeVoyageEmbeddings();
        $this->bindFakeAnthropicClient([
            ['card_id_b' => $card->id, 'description' => 'A self pair.'],
        ]);

        $this->handleComboJob(new TagCardCombos($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseMissing('combo_pairs', ['card_id_a' => $card->id, 'card_id_b' => $card->id]);
        $this->assertDatabaseCount('combo_pairs', 0);
    }

    public function test_it_discards_blank_descriptions_and_duplicate_partners(): void
    {
        [$card, $partner] = $this->cardWithSharedClassPartner();
        $this->fakeVoyageEmbeddings();
        $this->bindFakeAnthropicClient([
            ['card_id_b' => $partner->id, 'description' => 'First entry.'],
            ['card_id_b' => $partner->id, 'description' => 'Duplicate entry.'],
            ['card_id_b' => $partner->id, 'description' => ''],
        ]);

        $this->handleComboJob(new TagCardCombos($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseCount('combo_pairs', 1);
        $this->assertDatabaseHas('combo_pairs', [
            'card_id_a' => $card->id,
            'card_id_b' => $partner->id,
            'description' => 'First entry.',
        ]);
    }

    public function test_it_replaces_draft_rows_and_preserves_validated_rows(): void
    {
        [$card, $partner] = $this->cardWithSharedClassPartner();
        $staleDraftPartner = Card::factory()->create();
        ComboPair::factory()->create(['card_id_a' => $card->id, 'card_id_b' => $staleDraftPartner->id, 'status' => 'draft']);

        $validatedPartner = Card::factory()->create(['functional_text' => 'Validated partner text.']);
        $validatedPartner->classes()->attach($card->classes->first()->id);
        $validatedComboPair = ComboPair::factory()->validated()->create([
            'card_id_a' => $card->id,
            'card_id_b' => $validatedPartner->id,
            'description' => 'Existing validated combo.',
        ]);

        $this->fakeVoyageEmbeddings();
        $this->bindFakeAnthropicClient([
            ['card_id_b' => $partner->id, 'description' => 'New draft combo.'],
            ['card_id_b' => $validatedPartner->id, 'description' => 'Re-proposed validated combo.'],
        ]);

        $this->handleComboJob(new TagCardCombos($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseMissing('combo_pairs', ['card_id_a' => $card->id, 'card_id_b' => $staleDraftPartner->id]);
        $this->assertDatabaseHas('combo_pairs', ['card_id_a' => $card->id, 'card_id_b' => $partner->id, 'status' => 'draft']);

        $validatedComboPair->refresh();
        $this->assertSame('validated', $validatedComboPair->status);
        $this->assertSame('Existing validated combo.', $validatedComboPair->description);

        $this->assertDatabaseCount('combo_pairs', 2);
    }

    public function test_it_skips_when_there_are_no_candidates(): void
    {
        Http::preventStrayRequests();
        $card = Card::factory()->create(['functional_text' => 'Lonely card.']);

        $this->handleComboJob(new TagCardCombos($this->context(['cardId' => $card->id]), $card->id));

        Http::assertNothingSent();
        $this->assertDatabaseCount('combo_pairs', 0);
        $this->assertCount(1, array_filter(
            $this->loggedMessages,
            fn (array $e): bool => $e['message'] === 'enrichment.skipped' && ($e['context']['reason'] ?? null) === 'no_candidates',
        ));
    }

    public function test_it_uses_the_configured_combo_tagging_model(): void
    {
        config(['enrichment.models.combo_tagging' => 'claude-test-model']);
        [$card, $partner] = $this->cardWithSharedClassPartner();
        $this->fakeVoyageEmbeddings();
        $fake = $this->bindFakeAnthropicClient([
            ['card_id_b' => $partner->id, 'description' => 'A combo.'],
        ]);

        $this->handleComboJob(new TagCardCombos($this->context(['cardId' => $card->id]), $card->id));

        $body = json_decode((string) $fake->requests[0]->getBody(), true);
        $this->assertSame('claude-test-model', $body['model']);
    }

    public function test_the_default_combo_tagging_model_is_haiku_tier(): void
    {
        $this->assertStringContainsString('haiku', config('enrichment.models.combo_tagging'));
    }

    public function test_it_makes_no_external_calls_on_dry_run(): void
    {
        [$card, $partner] = $this->cardWithSharedClassPartner();
        Http::preventStrayRequests();
        $this->bindFakeAnthropicClient([
            ['card_id_b' => $partner->id, 'description' => 'Should never be sent.'],
        ]);

        $this->handleComboJob(new TagCardCombos($this->context(['cardId' => $card->id, 'dryRun' => true]), $card->id));

        Http::assertNothingSent();
        $this->assertDatabaseCount('combo_pairs', 0);
    }

    public function test_single_card_combo_run_writes_draft_rows(): void
    {
        [$card, $partner] = $this->cardWithSharedClassPartner();
        $this->fakeVoyageEmbeddings();
        $this->bindFakeAnthropicClient([
            ['card_id_b' => $partner->id, 'description' => 'A combo.'],
        ]);

        $this->artisan('enrich:run', ['--card' => $card->id, '--only' => 'combo'])->assertSuccessful();

        $this->assertDatabaseHas('combo_pairs', ['card_id_a' => $card->id, 'card_id_b' => $partner->id, 'status' => 'draft']);
    }

    public function test_planner_combo_scope_includes_cards_with_stale_combo_rows(): void
    {
        $staleCard = Card::factory()->create(['updated_at' => now()]);
        ComboPair::factory()->create(['card_id_a' => $staleCard->id, 'generated_at' => now()->subDay()]);

        $freshCard = Card::factory()->create(['updated_at' => now()->subDay()]);
        ComboPair::factory()->create(['card_id_a' => $freshCard->id, 'generated_at' => now()]);

        $missingCard = Card::factory()->create();

        $context = $this->context();
        $scope = app(EnrichmentPlanner::class)->cardScopeFor('combo', $context);

        $this->assertTrue($scope->contains($staleCard->id));
        $this->assertTrue($scope->contains($missingCard->id));
        $this->assertFalse($scope->contains($freshCard->id));
    }
}
