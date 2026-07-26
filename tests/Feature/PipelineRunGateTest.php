<?php

namespace Tests\Feature;

use App\Models\PipelineRun;
use App\Models\SourceSyncState;
use App\Services\PipelineGateDecision;
use App\Services\PipelineRunGate;
use App\Services\PipelineRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineRunGateTest extends TestCase
{
    use RefreshDatabase;

    private function gate(): PipelineRunGate
    {
        return new PipelineRunGate(new PipelineRunRecorder);
    }

    public function test_allows_first_ever_run(): void
    {
        $decision = $this->gate()->enrichmentShouldRun();

        $this->assertTrue($decision->allowed);
        $this->assertSame(PipelineGateDecision::REASON_NO_PRIOR_RUN, $decision->reason);
    }

    public function test_blocks_within_the_minimum_interval(): void
    {
        config(['enrichment.min_run_interval_hours' => 24]);

        $lastRun = PipelineRun::factory()->enrichment()->create([
            'finished_at' => now()->subHour(),
        ]);

        SourceSyncState::factory()->changed()->create([
            'source_key' => 'fab_cube_cards',
            'last_changed_at' => $lastRun->finished_at->addMinutes(10),
        ]);

        $decision = $this->gate()->enrichmentShouldRun();

        $this->assertFalse($decision->allowed);
        $this->assertSame(PipelineGateDecision::REASON_COOLDOWN, $decision->reason);
        $this->assertNotNull($decision->retryAfter);
        $this->assertTrue($decision->retryAfter->isFuture());
    }

    public function test_failed_run_does_not_reset_cooldown(): void
    {
        config(['enrichment.min_run_interval_hours' => 24]);

        $successfulRun = PipelineRun::factory()->enrichment()->create([
            'finished_at' => now()->subHours(30),
        ]);

        PipelineRun::factory()->enrichment()->failed()->create([
            'finished_at' => now()->subHour(),
        ]);

        SourceSyncState::factory()->changed()->create([
            'source_key' => 'fab_cube_cards',
            'last_changed_at' => $successfulRun->finished_at->addHour(),
        ]);

        $decision = $this->gate()->enrichmentShouldRun();

        $this->assertTrue($decision->allowed);
        $this->assertSame(PipelineGateDecision::REASON_SOURCE_CHANGED, $decision->reason);
    }

    public function test_force_bypasses_cooldown_but_not_concurrency(): void
    {
        PipelineRun::factory()->enrichment()->running()->create([
            'started_at' => now(),
        ]);

        $decision = $this->gate()->enrichmentShouldRun(force: true);

        $this->assertFalse($decision->allowed);
        $this->assertSame(PipelineGateDecision::REASON_ALREADY_RUNNING, $decision->reason);
    }

    public function test_only_ai_relevant_source_changes_allow_a_run(): void
    {
        config(['enrichment.min_run_interval_hours' => 24]);

        $relevantSources = config('pipeline.enrichment_relevant_sources');
        $allSources = [
            SourceSyncState::SOURCE_FAB_CUBE_CARDS,
            SourceSyncState::SOURCE_ERRATA_BULLETINS,
            SourceSyncState::SOURCE_FAB_CR_RULES,
            SourceSyncState::SOURCE_FABTCG_HERO_SELECTION,
            SourceSyncState::SOURCE_CARDVAULT_PRINTINGS,
            SourceSyncState::SOURCE_TCGCSV_PRICING,
            SourceSyncState::SOURCE_META_SNAPSHOTS,
            SourceSyncState::SOURCE_FABREC_STAPLE_STATS,
        ];

        foreach ($allSources as $sourceKey) {
            $lastRun = PipelineRun::factory()->enrichment()->create([
                'finished_at' => now()->subHours(48),
            ]);

            SourceSyncState::factory()->changed()->create([
                'source_key' => $sourceKey,
                'last_changed_at' => $lastRun->finished_at->addHour(),
            ]);

            $decision = $this->gate()->enrichmentShouldRun();

            if (in_array($sourceKey, $relevantSources, true)) {
                $this->assertTrue($decision->allowed, "Expected {$sourceKey} to allow a run.");
                $this->assertSame(PipelineGateDecision::REASON_SOURCE_CHANGED, $decision->reason);
            } else {
                $this->assertFalse($decision->allowed, "Expected {$sourceKey} not to allow a run.");
                $this->assertSame(PipelineGateDecision::REASON_NO_RELEVANT_CHANGES, $decision->reason);
            }

            PipelineRun::query()->delete();
            SourceSyncState::query()->delete();
        }
    }
}
