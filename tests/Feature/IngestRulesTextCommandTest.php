<?php

namespace Tests\Feature;

use App\Models\RulesTextVersion;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IngestRulesTextCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function baseFixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/fab-comprehensive-rules.txt'));
    }

    private function changedFixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/fab-comprehensive-rules-changed.txt'));
    }

    private function fakeUpstream(string $body, int $status = 200): void
    {
        Http::fake([
            '*' => Http::response($body, $status),
        ]);
    }

    /**
     * Registers a single upstream fake whose response body can be swapped mid-test
     * by mutating the returned holder's `body` property. A second call to
     * Http::fake('*') would not take effect, since Laravel resolves fakes in
     * registration order and the first matching `*` stub always wins.
     */
    private function fakeUpstreamMutable(string $initialBody): \stdClass
    {
        $state = new \stdClass;
        $state->body = $initialBody;

        Http::fake([
            '*' => fn () => Http::response($state->body),
        ]);

        return $state;
    }

    public function test_url_option_overrides_source_url(): void
    {
        Http::fake([
            'https://example.test/custom-cr.txt' => Http::response($this->baseFixture()),
        ]);

        $this->artisan('data:ingest-rules-text', ['--url' => 'https://example.test/custom-cr.txt'])
            ->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->url() === 'https://example.test/custom-cr.txt');
        $this->assertDatabaseCount('rules_text_versions', 1);
    }

    public function test_first_run_stores_snapshot_with_null_diff(): void
    {
        $body = $this->baseFixture();
        $this->fakeUpstream($body);

        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        $this->assertDatabaseCount('rules_text_versions', 1);

        $row = RulesTextVersion::firstOrFail();
        $this->assertSame($body, $row->full_text);
        $this->assertNull($row->diff_from_previous);
    }

    public function test_second_run_with_identical_text_appends_new_row(): void
    {
        $body = $this->baseFixture();
        $state = $this->fakeUpstreamMutable($body);

        $this->artisan('data:ingest-rules-text')->assertExitCode(0);
        $first = RulesTextVersion::firstOrFail();

        sleep(1);
        $state->body = $body;
        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        $this->assertDatabaseCount('rules_text_versions', 2);

        $first->refresh();
        $this->assertSame($body, $first->full_text);

        $newest = RulesTextVersion::query()->orderByDesc('fetched_at')->orderByDesc('id')->first();
        $this->assertNotSame($first->id, $newest->id);

        $diff = json_decode($newest->diff_from_previous, true);
        $this->assertSame(['added' => [], 'removed' => [], 'changed' => []], $diff);
    }

    public function test_tied_fetched_at_breaks_toward_the_most_recently_inserted_row(): void
    {
        $tiedAt = now();

        $older = RulesTextVersion::factory()->create([
            'fetched_at' => $tiedAt,
            'full_text' => "1.0.1 Only in the older row\n",
        ]);
        $newer = RulesTextVersion::factory()->create([
            'fetched_at' => $tiedAt,
            'full_text' => "1.0.2 Only in the newer row\n",
        ]);

        $this->assertSame($older->fetched_at->toDateTimeString(), $newer->fetched_at->toDateTimeString());

        $this->fakeUpstream($newer->full_text);

        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        $latest = RulesTextVersion::query()->orderByDesc('fetched_at')->orderByDesc('id')->first();
        $diff = json_decode($latest->diff_from_previous, true);

        // If the tie-break preferred the older row, this would instead show
        // `1.0.1` removed and `1.0.2` added.
        $this->assertSame(['added' => [], 'removed' => [], 'changed' => []], $diff);
    }

    public function test_changed_section_is_reported_in_diff(): void
    {
        $state = $this->fakeUpstreamMutable($this->baseFixture());
        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        sleep(1);
        $state->body = $this->changedFixture();
        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        $newest = RulesTextVersion::query()->orderByDesc('fetched_at')->orderByDesc('id')->first();
        $diff = json_decode($newest->diff_from_previous, true);

        $this->assertContains('1.0.1a', $diff['changed']);
        $this->assertNotContains('1.0.1a', $diff['added']);
        $this->assertNotContains('1.0.1a', $diff['removed']);
    }

    public function test_added_and_removed_sections_are_reported_in_diff(): void
    {
        $state = $this->fakeUpstreamMutable($this->baseFixture());
        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        sleep(1);
        $state->body = $this->changedFixture();
        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        $newest = RulesTextVersion::query()->orderByDesc('fetched_at')->orderByDesc('id')->first();
        $diff = json_decode($newest->diff_from_previous, true);

        $this->assertContains('1.0.3', $diff['added']);
        $this->assertContains('1.-1.1', $diff['removed']);
    }

    public function test_glossary_entry_with_negative_component_is_parsed_as_a_section(): void
    {
        $state = $this->fakeUpstreamMutable($this->baseFixture());
        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        sleep(1);
        $state->body = $this->changedFixture();
        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        $newest = RulesTextVersion::query()->orderByDesc('fetched_at')->orderByDesc('id')->first();
        $diff = json_decode($newest->diff_from_previous, true);

        $this->assertContains('1.-1.1', $diff['removed']);
    }

    public function test_chapter_headings_and_preamble_are_not_sections(): void
    {
        $base = $this->baseFixture();
        $editedPreamble = str_replace(
            'Flesh and Blood is a competitive trading card game where two heroes face off in a fight to the death.',
            'Flesh and Blood is a competitive trading card game where two heroes face off in a duel to the death.',
            $base,
        );
        $this->assertNotSame($base, $editedPreamble);

        $state = $this->fakeUpstreamMutable($base);
        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        sleep(1);
        $state->body = $editedPreamble;
        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        $newest = RulesTextVersion::query()->orderByDesc('fetched_at')->orderByDesc('id')->first();
        $diff = json_decode($newest->diff_from_previous, true);

        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame(['preamble'], $diff['changed']);
    }

    public function test_diff_lists_are_sorted_deterministically(): void
    {
        $state = $this->fakeUpstreamMutable($this->baseFixture());
        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        sleep(1);
        $state->body = $this->changedFixture();
        $this->artisan('data:ingest-rules-text')->assertExitCode(0);

        $newest = RulesTextVersion::query()->orderByDesc('fetched_at')->orderByDesc('id')->first();
        $diff = json_decode($newest->diff_from_previous, true);

        foreach (['added', 'removed', 'changed'] as $key) {
            $sorted = $diff[$key];
            sort($sorted);
            $this->assertSame($sorted, $diff[$key]);
            $this->assertSame(array_values($diff[$key]), $diff[$key]);
        }
    }

    public function test_non_2xx_upstream_response_aborts_cleanly(): void
    {
        $this->fakeUpstream('', 500);

        $exitCode = Artisan::call('data:ingest-rules-text');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertDatabaseCount('rules_text_versions', 0);
    }

    public function test_empty_body_aborts_cleanly_and_leaves_existing_rows_untouched(): void
    {
        $existing = RulesTextVersion::factory()->create();

        $this->fakeUpstream('   ');

        $exitCode = Artisan::call('data:ingest-rules-text');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertDatabaseCount('rules_text_versions', 1);

        $existing->refresh();
        $this->assertNotNull($existing->full_text);
    }
}
