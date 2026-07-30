<?php

namespace Tests\Feature;

use App\Jobs\Enrichment\PublishEnrichment;
use App\Models\Card;
use App\Models\CardExplainer;
use App\Models\ComboPair;
use App\Models\SynergyTag;
use App\Services\EnrichmentRunContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class PublishEnrichmentTest extends TestCase
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
    }

    private function context(array $overrides = []): EnrichmentRunContext
    {
        return new EnrichmentRunContext(
            pipelineRunId: $overrides['pipelineRunId'] ?? null,
            steps: ['publish'],
            fresh: $overrides['fresh'] ?? false,
            dryRun: $overrides['dryRun'] ?? false,
            cardId: $overrides['cardId'] ?? null,
            via: $overrides['via'] ?? 'api',
            triggeredBy: 'manual',
            promptVersion: $overrides['promptVersion'] ?? 'v0',
        );
    }

    private function handleJob(PublishEnrichment $job): void
    {
        $job->handle();
    }

    private function publishedLogs(): array
    {
        return array_values(array_filter(
            $this->loggedMessages,
            fn (array $e): bool => $e['message'] === 'enrichment.published'
        ));
    }

    public function test_it_promotes_a_validated_explainer_to_published(): void
    {
        $explainer = CardExplainer::factory()->validated()->create();

        $this->handleJob(new PublishEnrichment($this->context()));

        $explainer->refresh();
        $this->assertSame('published', $explainer->status);
        $this->assertNotNull($explainer->published_at);
    }

    public function test_it_promotes_a_validated_combo_pair_to_published(): void
    {
        $combo = ComboPair::factory()->validated()->create();

        $this->handleJob(new PublishEnrichment($this->context()));

        $combo->refresh();
        $this->assertSame('published', $combo->status);
        $this->assertNotNull($combo->published_at);
    }

    public function test_it_promotes_a_validated_card_synergy_tag_row_to_published(): void
    {
        $card = Card::factory()->create();
        $tag = SynergyTag::factory()->create();
        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $tag->id,
            'status' => 'validated',
        ]);

        $this->handleJob(new PublishEnrichment($this->context()));

        $row = DB::table('card_synergy_tags')->where('card_id', $card->id)->where('synergy_tag_id', $tag->id)->first();
        $this->assertSame('published', $row->status);
        $this->assertNotNull($row->published_at);
    }

    public function test_draft_rows_are_left_untouched(): void
    {
        $explainer = CardExplainer::factory()->create(['status' => 'draft']);
        $combo = ComboPair::factory()->create(['status' => 'draft']);
        $card = Card::factory()->create();
        $tag = SynergyTag::factory()->create();
        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $tag->id,
            'status' => 'draft',
        ]);

        $this->handleJob(new PublishEnrichment($this->context()));

        $explainer->refresh();
        $combo->refresh();
        $this->assertSame('draft', $explainer->status);
        $this->assertNull($explainer->published_at);
        $this->assertSame('draft', $combo->status);
        $this->assertNull($combo->published_at);

        $row = DB::table('card_synergy_tags')->where('card_id', $card->id)->where('synergy_tag_id', $tag->id)->first();
        $this->assertSame('draft', $row->status);
        $this->assertNull($row->published_at);
    }

    public function test_re_running_after_a_successful_publish_is_a_no_op(): void
    {
        $explainer = CardExplainer::factory()->validated()->create();

        $this->handleJob(new PublishEnrichment($this->context()));
        $publishedAt = $explainer->refresh()->published_at;

        $this->handleJob(new PublishEnrichment($this->context()));

        $explainer->refresh();
        $this->assertSame('published', $explainer->status);
        $this->assertTrue($publishedAt->equalTo($explainer->published_at));

        $logs = $this->publishedLogs();
        $this->assertCount(2, $logs);
        $this->assertSame(0, $logs[1]['context']['counts']['explainers']);
        $this->assertSame(0, $logs[1]['context']['counts']['combos']);
        $this->assertSame(0, $logs[1]['context']['counts']['synergies']);
    }

    public function test_dry_run_makes_no_writes_and_emits_no_published_event(): void
    {
        $explainer = CardExplainer::factory()->validated()->create();

        $this->handleJob(new PublishEnrichment($this->context(['dryRun' => true])));

        $explainer->refresh();
        $this->assertSame('validated', $explainer->status);
        $this->assertNull($explainer->published_at);
        $this->assertCount(0, $this->publishedLogs());
    }

    public function test_it_logs_the_published_event_with_per_table_counts(): void
    {
        CardExplainer::factory()->validated()->create();
        ComboPair::factory()->validated()->create();

        $this->handleJob(new PublishEnrichment($this->context()));

        $logs = $this->publishedLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('publish', $logs[0]['context']['step']);
        $this->assertSame(1, $logs[0]['context']['counts']['explainers']);
        $this->assertSame(1, $logs[0]['context']['counts']['combos']);
        $this->assertSame(0, $logs[0]['context']['counts']['synergies']);
    }

    public function test_a_failure_partway_through_rolls_back_all_three_tables(): void
    {
        $explainer = CardExplainer::factory()->validated()->create();
        $combo = ComboPair::factory()->validated()->create();
        $card = Card::factory()->create();
        $tag = SynergyTag::factory()->create();
        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $tag->id,
            'status' => 'validated',
        ]);

        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'card_synergy_tags')) {
                throw new RuntimeException('forced failure');
            }
        });

        try {
            $this->handleJob(new PublishEnrichment($this->context()));
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('forced failure', $e->getMessage());
        }

        $explainer->refresh();
        $combo->refresh();
        $this->assertSame('validated', $explainer->status);
        $this->assertNull($explainer->published_at);
        $this->assertSame('validated', $combo->status);
        $this->assertNull($combo->published_at);

        $row = DB::table('card_synergy_tags')->where('card_id', $card->id)->where('synergy_tag_id', $tag->id)->first();
        $this->assertSame('validated', $row->status);
        $this->assertNull($row->published_at);
    }
}
