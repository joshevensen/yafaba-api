<?php

namespace Tests\Feature;

use App\Models\PipelineRun;
use App\Services\PipelineRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineRunRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_creates_a_running_pipeline_run_and_acquires_a_lock(): void
    {
        $recorder = new PipelineRunRecorder;

        $run = $recorder->start(PipelineRun::PHASE_DATA, 'data:ingest-cards', PipelineRun::TRIGGER_CLI);

        $this->assertNotNull($run);
        $this->assertSame(PipelineRun::PHASE_DATA, $run->phase);
        $this->assertSame('data:ingest-cards', $run->command);
        $this->assertSame(PipelineRun::TRIGGER_CLI, $run->triggered_by);
        $this->assertSame(PipelineRun::STATUS_RUNNING, $run->status);
        $this->assertTrue($run->is_running);
        $this->assertNotNull($run->started_at);
        $this->assertNull($run->finished_at);
    }

    public function test_second_start_for_same_phase_is_refused_while_running(): void
    {
        $recorder = new PipelineRunRecorder;

        $first = $recorder->start(PipelineRun::PHASE_DATA, 'data:ingest-cards', PipelineRun::TRIGGER_CLI);
        $second = $recorder->start(PipelineRun::PHASE_DATA, 'data:ingest-cards', PipelineRun::TRIGGER_CLI);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, PipelineRun::forPhase(PipelineRun::PHASE_DATA)->count());
    }

    public function test_finish_sets_terminal_state_and_releases_the_lock(): void
    {
        $recorder = new PipelineRunRecorder;

        $run = $recorder->start(PipelineRun::PHASE_DATA, 'data:ingest-cards', PipelineRun::TRIGGER_CLI);
        $finished = $recorder->finish($run, PipelineRun::STATUS_SUCCESS, ['created' => 5, 'updated' => 2]);

        $this->assertNotNull($finished->finished_at);
        $this->assertSame(PipelineRun::STATUS_SUCCESS, $finished->status);
        $this->assertSame(['created' => 5, 'updated' => 2], $finished->counts);
        $this->assertFalse($finished->is_running);

        $second = $recorder->start(PipelineRun::PHASE_DATA, 'data:ingest-cards', PipelineRun::TRIGGER_CLI);
        $this->assertNotNull($second);
    }

    public function test_fail_sets_failed_status_and_error(): void
    {
        $recorder = new PipelineRunRecorder;

        $run = $recorder->start(PipelineRun::PHASE_DATA, 'data:ingest-cards', PipelineRun::TRIGGER_CLI);
        $failed = $recorder->fail($run, 'Something went wrong', ['created' => 1]);

        $this->assertNotNull($failed->finished_at);
        $this->assertSame(PipelineRun::STATUS_FAILED, $failed->status);
        $this->assertSame('Something went wrong', $failed->error);
        $this->assertSame(['created' => 1], $failed->counts);
        $this->assertFalse($failed->is_running);
    }

    public function test_stale_running_rows_are_reconciled_and_unblock_new_runs(): void
    {
        $recorder = new PipelineRunRecorder;

        $stale = PipelineRun::factory()->running()->create([
            'phase' => PipelineRun::PHASE_DATA,
            'started_at' => now()->subMinutes(config('pipeline.stale_run_after_minutes') + 30),
        ]);

        $reconciledCount = $recorder->reconcileStale();

        $this->assertGreaterThanOrEqual(1, $reconciledCount);

        $stale->refresh();
        $this->assertSame(PipelineRun::STATUS_FAILED, $stale->status);
        $this->assertFalse($stale->is_running);

        $newRun = $recorder->start(PipelineRun::PHASE_DATA, 'data:ingest-cards', PipelineRun::TRIGGER_CLI);
        $this->assertNotNull($newRun);
    }
}
