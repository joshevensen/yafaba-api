<?php

namespace Tests\Feature;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Jobs\Enrichment\TagCardSynergies;
use App\Models\Card;
use App\Models\SynergyTag;
use App\Services\Enrichment\KbRetriever;
use App\Services\Enrichment\SynergyTaggingPromptBuilder;
use App\Services\EnrichmentPlanner;
use App\Services\EnrichmentRunContext;
use App\Services\Llm\LlmTransportFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\FakePsr18Client;
use Tests\TestCase;

class TagCardSynergiesTest extends TestCase
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
            steps: ['synergy'],
            fresh: $overrides['fresh'] ?? false,
            dryRun: $overrides['dryRun'] ?? false,
            cardId: $overrides['cardId'] ?? null,
            via: $overrides['via'] ?? 'api',
            triggeredBy: 'manual',
            promptVersion: $overrides['promptVersion'] ?? 'v0',
        );
    }

    private function handleSynergyJob(TagCardSynergies $job): void
    {
        $job->handle(
            app(KbRetriever::class),
            app(SynergyTaggingPromptBuilder::class),
            app(LlmTransportFactory::class),
        );
    }

    /**
     * @param  list<array{synergy_tag_id: string}>  $tags
     * @param  list<array{name: string, description: string}>  $newTags
     */
    private function bindFakeAnthropicClient(array $tags, array $newTags = []): FakePsr18Client
    {
        $body = [
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-4-5-20251001',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'emit_result', 'input' => ['tags' => $tags, 'new_tags' => $newTags]]],
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

    public function test_it_writes_draft_synergy_tag_rows(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $tag = SynergyTag::factory()->create();
        $this->bindFakeAnthropicClient([['synergy_tag_id' => $tag->id]]);

        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseHas('card_synergy_tags', [
            'card_id' => $card->id,
            'synergy_tag_id' => $tag->id,
            'status' => 'draft',
        ]);

        $row = DB::table('card_synergy_tags')->where('card_id', $card->id)->where('synergy_tag_id', $tag->id)->first();
        $this->assertSame($card->source_hash, $row->source_hash);
        $this->assertSame('v0', $row->prompt_version);
        $this->assertNotNull($row->generated_at);
    }

    public function test_it_discards_assignments_for_tags_not_in_the_vocabulary(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $proposedTag = SynergyTag::factory()->proposed()->create();
        $this->bindFakeAnthropicClient([['synergy_tag_id' => $proposedTag->id], ['synergy_tag_id' => (string) Str::uuid()]]);

        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseCount('card_synergy_tags', 0);
    }

    public function test_it_discards_duplicate_tag_assignments(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $tag = SynergyTag::factory()->create();
        $this->bindFakeAnthropicClient([['synergy_tag_id' => $tag->id], ['synergy_tag_id' => $tag->id]]);

        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseCount('card_synergy_tags', 1);
    }

    public function test_it_inserts_new_tag_proposals_as_proposed_and_links_them_to_the_card(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $this->bindFakeAnthropicClient([], [['name' => 'brand-new-theme', 'description' => 'A new recurring theme.']]);

        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseHas('synergy_tags', [
            'name' => 'brand-new-theme',
            'description' => 'A new recurring theme.',
            'status' => 'proposed',
        ]);

        $newTag = SynergyTag::where('name', 'brand-new-theme')->firstOrFail();
        $this->assertDatabaseHas('card_synergy_tags', [
            'card_id' => $card->id,
            'synergy_tag_id' => $newTag->id,
            'status' => 'draft',
        ]);
    }

    public function test_it_silently_skips_new_tag_proposals_that_collide_with_existing_names(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        SynergyTag::factory()->create(['name' => 'Existing-Theme']);
        SynergyTag::factory()->proposed()->create(['name' => 'other-proposed-theme']);
        $this->bindFakeAnthropicClient([], [
            ['name' => 'existing-theme', 'description' => 'Collides with an approved tag.'],
            ['name' => 'OTHER-PROPOSED-THEME', 'description' => 'Collides with a proposed tag.'],
        ]);

        $countBefore = SynergyTag::count();

        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id));

        $this->assertSame($countBefore, SynergyTag::count());
    }

    public function test_it_discards_blank_and_duplicate_new_tag_proposals(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $this->bindFakeAnthropicClient([], [
            ['name' => 'first-theme', 'description' => 'First.'],
            ['name' => 'first-theme', 'description' => 'Duplicate.'],
            ['name' => '', 'description' => 'Blank name.'],
            ['name' => 'blank-description', 'description' => ''],
        ]);

        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseCount('synergy_tags', 1);
        $this->assertDatabaseHas('synergy_tags', ['name' => 'first-theme', 'description' => 'First.']);
    }

    public function test_it_discards_new_tag_proposals_with_an_oversized_name(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $this->bindFakeAnthropicClient([], [
            ['name' => str_repeat('a', 256), 'description' => 'Name exceeds the column length.'],
        ]);

        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseCount('synergy_tags', 0);
    }

    public function test_it_replaces_draft_rows_and_preserves_validated_rows(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);

        $staleDraftTag = SynergyTag::factory()->create();
        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $staleDraftTag->id,
            'status' => 'draft',
        ]);

        $validatedTag = SynergyTag::factory()->create();
        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $validatedTag->id,
            'status' => 'validated',
        ]);

        $newDraftTag = SynergyTag::factory()->create();
        $this->bindFakeAnthropicClient([
            ['synergy_tag_id' => $newDraftTag->id],
            ['synergy_tag_id' => $validatedTag->id],
        ]);

        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseMissing('card_synergy_tags', ['card_id' => $card->id, 'synergy_tag_id' => $staleDraftTag->id]);
        $this->assertDatabaseHas('card_synergy_tags', ['card_id' => $card->id, 'synergy_tag_id' => $newDraftTag->id, 'status' => 'draft']);

        $validatedRow = DB::table('card_synergy_tags')->where('card_id', $card->id)->where('synergy_tag_id', $validatedTag->id)->first();
        $this->assertSame('validated', $validatedRow->status);

        $this->assertSame(2, DB::table('card_synergy_tags')->where('card_id', $card->id)->count());
    }

    public function test_it_skips_when_the_card_has_no_functional_text(): void
    {
        Http::preventStrayRequests();
        $card = Card::factory()->create(['functional_text' => null]);

        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id));

        Http::assertNothingSent();
        $this->assertDatabaseCount('card_synergy_tags', 0);
        $this->assertCount(1, array_filter(
            $this->loggedMessages,
            fn (array $e): bool => $e['message'] === 'enrichment.skipped' && ($e['context']['reason'] ?? null) === 'no_functional_text',
        ));
    }

    public function test_it_still_calls_the_model_when_the_vocabulary_is_empty(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $this->bindFakeAnthropicClient([], [['name' => 'first-ever-theme', 'description' => 'Bootstraps the vocabulary.']]);

        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id));

        $this->assertDatabaseHas('synergy_tags', ['name' => 'first-ever-theme', 'status' => 'proposed']);
        $this->assertCount(1, $this->loggedWithMessage('enrichment.synergies_tagged'));
    }

    public function test_it_uses_the_configured_synergy_tagging_model(): void
    {
        config(['enrichment.models.synergy_tagging' => 'claude-test-model']);
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $tag = SynergyTag::factory()->create();
        $fake = $this->bindFakeAnthropicClient([['synergy_tag_id' => $tag->id]]);

        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id));

        $body = json_decode((string) $fake->requests[0]->getBody(), true);
        $this->assertSame('claude-test-model', $body['model']);
    }

    public function test_the_default_synergy_tagging_model_is_haiku_tier(): void
    {
        $this->assertStringContainsString('haiku', config('enrichment.models.synergy_tagging'));
    }

    public function test_it_makes_no_external_calls_on_dry_run(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        Http::preventStrayRequests();
        $tag = SynergyTag::factory()->create();
        $this->bindFakeAnthropicClient([['synergy_tag_id' => $tag->id]]);

        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id, 'dryRun' => true]), $card->id));

        Http::assertNothingSent();
        $this->assertDatabaseCount('card_synergy_tags', 0);
    }

    public function test_single_card_synergy_run_writes_draft_rows(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $tag = SynergyTag::factory()->create();
        $this->bindFakeAnthropicClient([['synergy_tag_id' => $tag->id]]);

        $this->artisan('enrich:run', ['--card' => $card->id, '--only' => 'synergy'])->assertSuccessful();

        $this->assertDatabaseHas('card_synergy_tags', ['card_id' => $card->id, 'synergy_tag_id' => $tag->id, 'status' => 'draft']);
    }

    public function test_it_does_not_skip_when_any_draft_row_is_stale(): void
    {
        $card = Card::factory()->create(['source_hash' => 'hash-1', 'updated_at' => now()->subDay(), 'functional_text' => null]);
        $currentTag = SynergyTag::factory()->create();
        $staleTag = SynergyTag::factory()->create();

        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $currentTag->id,
            'status' => 'draft',
            'source_hash' => 'hash-1',
            'prompt_version' => 'v0',
            'generated_at' => now(),
        ]);
        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $staleTag->id,
            'status' => 'draft',
            'source_hash' => 'hash-2',
            'prompt_version' => 'v0',
            'generated_at' => now(),
        ]);

        // functional_text is null, so the real implementation hits the
        // no_functional_text skip path rather than calling the LLM - this
        // proves isUpToDate() returned false (one stale row), not that a
        // tag was actually written.
        $this->handleSynergyJob(new TagCardSynergies($this->context(['cardId' => $card->id]), $card->id));

        $skipped = $this->loggedWithMessage('enrichment.skipped');
        $this->assertCount(1, $skipped);
        $this->assertSame('no_functional_text', $skipped[0]['context']['reason']);
    }

    public function test_planner_synergy_scope_includes_cards_with_stale_or_missing_synergy_rows(): void
    {
        $staleCard = Card::factory()->create(['updated_at' => now()]);
        $staleTag = SynergyTag::factory()->create();
        DB::table('card_synergy_tags')->insert([
            'card_id' => $staleCard->id,
            'synergy_tag_id' => $staleTag->id,
            'status' => 'draft',
            'generated_at' => now()->subDay(),
        ]);

        $freshCard = Card::factory()->create(['updated_at' => now()->subDay()]);
        $freshTag = SynergyTag::factory()->create();
        DB::table('card_synergy_tags')->insert([
            'card_id' => $freshCard->id,
            'synergy_tag_id' => $freshTag->id,
            'status' => 'draft',
            'generated_at' => now(),
        ]);

        $missingCard = Card::factory()->create();

        $scope = app(EnrichmentPlanner::class)->cardScopeFor('synergy', $this->context());

        $this->assertTrue($scope->contains($staleCard->id));
        $this->assertTrue($scope->contains($missingCard->id));
        $this->assertFalse($scope->contains($freshCard->id));
    }

    public function test_synergy_tag_status_defaults_to_approved(): void
    {
        $tagId = (string) Str::uuid();
        DB::table('synergy_tags')->insert(['id' => $tagId, 'name' => 'default-status-test']);

        $this->assertSame('approved', DB::table('synergy_tags')->where('id', $tagId)->value('status'));
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
}
