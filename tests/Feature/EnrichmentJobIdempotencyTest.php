<?php

namespace Tests\Feature;

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
use App\Services\EnrichmentRunContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function test_generate_card_explainer_skips_when_up_to_date(): void
    {
        $card = Card::factory()->create(['updated_at' => now()->subDay()]);
        CardExplainer::factory()->create(['card_id' => $card->id, 'generated_at' => now()]);

        (new GenerateCardExplainer($this->context(['cardId' => $card->id]), $card->id))->handle();

        $this->assertCount(1, $this->loggedWithMessage('enrichment.skipped'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.stub'));
    }

    public function test_generate_card_explainer_does_not_skip_when_fresh(): void
    {
        $card = Card::factory()->create(['updated_at' => now()->subDay()]);
        CardExplainer::factory()->create(['card_id' => $card->id, 'generated_at' => now()]);

        (new GenerateCardExplainer($this->context(['cardId' => $card->id, 'fresh' => true]), $card->id))->handle();

        $this->assertCount(1, $this->loggedWithMessage('enrichment.stub'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.skipped'));
    }

    public function test_generate_card_explainer_respects_dry_run(): void
    {
        $card = Card::factory()->create();

        (new GenerateCardExplainer($this->context(['cardId' => $card->id, 'dryRun' => true]), $card->id))->handle();

        $this->assertCount(1, $this->loggedWithMessage('enrichment.dry_run'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.stub'));
        $this->assertDatabaseCount('card_explainers', 0);
    }

    public function test_tag_card_combos_skips_when_up_to_date(): void
    {
        $card = Card::factory()->create();
        ComboPair::factory()->create(['card_id_a' => $card->id]);

        (new TagCardCombos($this->context(['cardId' => $card->id]), $card->id))->handle();

        $this->assertCount(1, $this->loggedWithMessage('enrichment.skipped'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.stub'));
    }

    public function test_tag_card_combos_does_not_skip_when_fresh(): void
    {
        $card = Card::factory()->create();
        ComboPair::factory()->create(['card_id_a' => $card->id]);

        (new TagCardCombos($this->context(['cardId' => $card->id, 'fresh' => true]), $card->id))->handle();

        $this->assertCount(1, $this->loggedWithMessage('enrichment.stub'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.skipped'));
    }

    public function test_tag_card_combos_respects_dry_run(): void
    {
        $card = Card::factory()->create();

        $countBefore = ComboPair::count();

        (new TagCardCombos($this->context(['cardId' => $card->id, 'dryRun' => true]), $card->id))->handle();

        $this->assertCount(1, $this->loggedWithMessage('enrichment.dry_run'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.stub'));
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
        RulesTextVersion::factory()->create(['fetched_at' => now()->subDay()]);
        ErrataBulletin::factory()->create(['cached_at' => now()->subDay()]);
        KbDocument::factory()->create(['created_at' => now()]);

        (new BuildKnowledgeBase($this->context()))->handle();

        $this->assertCount(1, $this->loggedWithMessage('enrichment.skipped'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.stub'));
    }

    public function test_build_knowledge_base_does_not_skip_when_fresh(): void
    {
        RulesTextVersion::factory()->create(['fetched_at' => now()->subDay()]);
        ErrataBulletin::factory()->create(['cached_at' => now()->subDay()]);
        KbDocument::factory()->create(['created_at' => now()]);

        (new BuildKnowledgeBase($this->context(['fresh' => true])))->handle();

        $this->assertCount(1, $this->loggedWithMessage('enrichment.stub'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.skipped'));
    }

    public function test_build_knowledge_base_respects_dry_run(): void
    {
        $countBefore = KbDocument::count();

        (new BuildKnowledgeBase($this->context(['dryRun' => true])))->handle();

        $this->assertCount(1, $this->loggedWithMessage('enrichment.dry_run'));
        $this->assertCount(0, $this->loggedWithMessage('enrichment.stub'));
        $this->assertSame($countBefore, KbDocument::count());
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
