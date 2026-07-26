<?php

namespace Tests\Feature;

use App\Models\PipelineRun;
use App\Models\SourceSyncState;
use App\Services\PipelineRunRecorder;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule as ScheduleRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CheckSourceFreshnessCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function githubCommitsFixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/github-commits-card-json.json')), true);
    }

    public function test_github_commit_probe_stores_commit_sha_as_fingerprint(): void
    {
        Http::fake([
            '*api.github.com/repos/the-fab-cube/flesh-and-blood-cards/commits*' => Http::response($this->githubCommitsFixture()),
        ]);

        $exitCode = $this->artisan('data:check-sources', ['--source' => ['fab_cube_cards'], '--check-only' => true])->run();

        $this->assertSame(Command::SUCCESS, $exitCode);

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), 'api.github.com/repos/the-fab-cube/flesh-and-blood-cards/commits')
                && ($request['per_page'] ?? null) == 1;
        });

        $state = SourceSyncState::where('source_key', 'fab_cube_cards')->firstOrFail();
        $this->assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0', $state->fingerprint);
        $this->assertSame('git_sha', $state->fingerprint_type);
    }

    public function test_github_probe_authorizes_only_when_token_configured(): void
    {
        Http::fake([
            '*api.github.com/repos/the-fab-cube/flesh-and-blood-cards/commits*' => Http::response($this->githubCommitsFixture()),
        ]);

        config(['services.github.token' => 'test-token-123']);
        $this->artisan('data:check-sources', ['--source' => ['fab_cube_cards'], '--check-only' => true])->run();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token-123'));

        config(['services.github.token' => null]);
        $this->artisan('data:check-sources', ['--source' => ['fab_cube_cards'], '--check-only' => true])->run();

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'api.github.com') && ! $request->hasHeader('Authorization'));
    }

    public function test_unchanged_source_is_not_ingested(): void
    {
        Http::fake([
            'rules.fabtcg.com/*' => Http::response('Comprehensive Rules text.', 200, ['ETag' => '"abc123"']),
        ]);

        $state = SourceSyncState::factory()->create([
            'source_key' => 'fab_cr_rules',
            'fingerprint' => 'abc123',
            'fingerprint_type' => SourceSyncState::FINGERPRINT_ETAG,
            'pulled_fingerprint' => 'abc123',
            'last_pulled_at' => now()->subDay(),
        ]);
        $originalPulledAt = $state->last_pulled_at;

        $exitCode = $this->artisan('data:check-sources', ['--source' => ['fab_cr_rules']])->run();

        $this->assertSame(Command::SUCCESS, $exitCode);
        Http::assertNotSent(fn ($request) => $request->method() === 'GET' && str_contains((string) $request->url(), 'rules.fabtcg.com'));

        $state->refresh();
        $this->assertTrue($state->last_pulled_at->equalTo($originalPulledAt));
    }

    public function test_changed_source_triggers_ingest_and_records_pull(): void
    {
        Http::fake([
            'rules.fabtcg.com/*' => Http::response('Comprehensive Rules text.', 200, ['ETag' => '"def456"']),
        ]);

        $exitCode = $this->artisan('data:check-sources', ['--source' => ['fab_cr_rules']])->run();

        $this->assertSame(Command::SUCCESS, $exitCode);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains((string) $request->url(), 'rules.fabtcg.com'));

        $state = SourceSyncState::where('source_key', 'fab_cr_rules')->firstOrFail();
        $this->assertSame('def456', $state->pulled_fingerprint);
        $this->assertNotNull($state->last_pulled_at);
    }

    public function test_probe_failure_is_isolated_and_records_error(): void
    {
        Http::fake([
            'rules.fabtcg.com/*' => Http::response(null, 500),
        ]);

        Log::spy();

        $exitCode = $this->artisan('data:check-sources', [
            '--source' => ['fab_cr_rules', 'tcgcsv_pricing'],
            '--check-only' => true,
        ])->run();

        $this->assertSame(Command::SUCCESS, $exitCode);

        $failedState = SourceSyncState::where('source_key', 'fab_cr_rules')->firstOrFail();
        $this->assertNotNull($failedState->last_error);

        $okState = SourceSyncState::where('source_key', 'tcgcsv_pricing')->firstOrFail();
        $this->assertNull($okState->last_error);
        $this->assertNotNull($okState->last_checked_at);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'probe failed'))->atLeast()->once();
    }

    public function test_failed_ingest_does_not_mark_source_pulled(): void
    {
        Http::fake([
            'rules.fabtcg.com/*' => Http::response('', 200, ['ETag' => '"ghi789"']),
        ]);

        Log::spy();

        $exitCode = $this->artisan('data:check-sources', ['--source' => ['fab_cr_rules']])->run();

        // Only one source was processed and its ingest failed, so per the
        // "FAILURE only when every processed source failed" convention
        // mirrored from IngestMetaSnapshots, the overall run is FAILURE here.
        $this->assertSame(Command::FAILURE, $exitCode);

        $state = SourceSyncState::where('source_key', 'fab_cr_rules')->firstOrFail();
        $this->assertNull($state->pulled_fingerprint);
        $this->assertNull($state->last_pulled_at);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Ingest command failed'))->atLeast()->once();
    }

    public function test_check_only_updates_state_without_ingesting(): void
    {
        Http::fake([
            'rules.fabtcg.com/*' => Http::response('Comprehensive Rules text.', 200, ['ETag' => '"jkl012"']),
        ]);

        $exitCode = $this->artisan('data:check-sources', ['--source' => ['fab_cr_rules'], '--check-only' => true])->run();

        $this->assertSame(Command::SUCCESS, $exitCode);
        Http::assertNotSent(fn ($request) => $request->method() === 'GET' && str_contains((string) $request->url(), 'rules.fabtcg.com'));

        $state = SourceSyncState::where('source_key', 'fab_cr_rules')->firstOrFail();
        $this->assertSame('jkl012', $state->fingerprint);
        $this->assertNotNull($state->last_checked_at);
        $this->assertNull($state->pulled_fingerprint);
    }

    public function test_force_ingests_unchanged_source(): void
    {
        Http::fake([
            'rules.fabtcg.com/*' => Http::response('Comprehensive Rules text.', 200, ['ETag' => '"same123"']),
        ]);

        SourceSyncState::factory()->create([
            'source_key' => 'fab_cr_rules',
            'fingerprint' => 'same123',
            'fingerprint_type' => SourceSyncState::FINGERPRINT_ETAG,
            'pulled_fingerprint' => 'same123',
        ]);

        $exitCode = $this->artisan('data:check-sources', ['--source' => ['fab_cr_rules'], '--force' => true])->run();

        $this->assertSame(Command::SUCCESS, $exitCode);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains((string) $request->url(), 'rules.fabtcg.com'));
    }

    public function test_records_a_pipeline_run(): void
    {
        Http::fake([
            'rules.fabtcg.com/*' => Http::response('Comprehensive Rules text.', 200, ['ETag' => '"abc"']),
        ]);

        $this->artisan('data:check-sources', ['--source' => ['fab_cr_rules'], '--check-only' => true, '--triggered-by' => 'manual'])->run();

        $this->assertSame(1, PipelineRun::where('phase', 'data')->count());

        $run = PipelineRun::where('phase', 'data')->firstOrFail();
        $this->assertSame('manual', $run->triggered_by);
        $this->assertContains($run->status, [PipelineRun::STATUS_SUCCESS, PipelineRun::STATUS_PARTIAL, PipelineRun::STATUS_FAILED]);
        $this->assertNotNull($run->finished_at);
        $this->assertFalse($run->is_running);
        $this->assertNotEmpty($run->counts);
    }

    public function test_skips_when_a_data_run_is_already_in_progress(): void
    {
        app(PipelineRunRecorder::class)->start(PipelineRun::PHASE_DATA, 'other-command', 'cli');

        $exitCode = $this->artisan('data:check-sources')->run();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(1, PipelineRun::count());
        Http::assertNothingSent();
    }

    public function test_command_is_scheduled_daily(): void
    {
        $events = app(ScheduleRunner::class)->events();

        $matching = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'data:check-sources'));

        $this->assertNotNull($matching);
        $this->assertSame('0 9 * * *', $matching->expression);
    }
}
