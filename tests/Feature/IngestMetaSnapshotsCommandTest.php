<?php

namespace Tests\Feature;

use App\Console\Commands\IngestMetaSnapshots;
use App\Models\Card;
use App\Models\Format;
use App\Models\MetaSnapshot;
use Database\Seeders\FormatSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class IngestMetaSnapshotsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function fabtcgmetaCcFixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/fabtcgmeta-hero-performance-cc.json')), true);
    }

    private function fabtcgmetaLlFixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/fabtcgmeta-hero-performance-ll.json')), true);
    }

    private function fabtcgmetaSageFixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/fabtcgmeta-hero-performance-sage.json')), true);
    }

    private function fablazingHtml(string $slug): string
    {
        return file_get_contents(base_path("tests/Fixtures/fablazing-meta-{$slug}.html"));
    }

    /**
     * Fakes every URL the command touches with the real captured fixtures.
     * Uses distinct, non-overlapping wildcard patterns per format/slug so
     * registration order never matters.
     */
    private function fakeAllSources(): void
    {
        Http::fake([
            '*api.fabtcgmeta.com*format=CC-O*' => Http::response($this->fabtcgmetaCcFixture()),
            '*api.fabtcgmeta.com*format=LL-O*' => Http::response($this->fabtcgmetaLlFixture()),
            '*api.fabtcgmeta.com*Silver*Age*' => Http::response($this->fabtcgmetaSageFixture()),
            '*fablazing.com/meta/cc' => Http::response($this->fablazingHtml('cc')),
            '*fablazing.com/meta/ll' => Http::response($this->fablazingHtml('ll')),
            '*fablazing.com/meta/sage' => Http::response($this->fablazingHtml('sage')),
            '*fablazing.com/meta/gage' => Http::response($this->fablazingHtml('gage')),
        ]);
    }

    private function seedFormats(): void
    {
        $this->seed(FormatSeeder::class);
    }

    private function seedKnownHeroCards(): void
    {
        Card::factory()->hero()->create(['name' => 'Arakni, Orb Weaver']);
        Card::factory()->hero()->create(['name' => 'Teklovossen, the Mechropotent']);
        Card::factory()->hero()->create(['name' => 'Oscilio, Constella Intelligence']);
    }

    /**
     * Resolves and runs a brand new command instance from the container, the
     * same way each separate `php artisan` invocation would in production.
     * Laravel's console Application reuses a single registered Command
     * instance across repeated `$this->artisan()` calls within one test, so
     * the command's per-run counters would otherwise leak between what are
     * meant to be two independent runs.
     */
    private function runFresh(): int
    {
        $command = app(IngestMetaSnapshots::class);
        $command->setLaravel(app());

        return $command->run(new ArrayInput([]), new NullOutput);
    }

    public function test_fails_when_no_hero_cards_exist(): void
    {
        $this->seedFormats();

        $exitCode = $this->artisan('data:ingest-meta')->run();

        $this->assertNotEquals(Command::SUCCESS, $exitCode);
        $this->assertSame(0, MetaSnapshot::count());
        Http::assertNothingSent();
    }

    public function test_fails_when_no_canonical_formats_exist(): void
    {
        Card::factory()->hero()->create();

        $exitCode = $this->artisan('data:ingest-meta')->run();

        $this->assertNotEquals(Command::SUCCESS, $exitCode);
        Http::assertNothingSent();
    }

    public function test_creates_meta_snapshot_rows_from_both_sources(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        $exitCode = $this->artisan('data:ingest-meta')->run();

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertTrue(MetaSnapshot::where('source', 'fabtcgmeta')->exists());
        $this->assertTrue(MetaSnapshot::where('source', 'fablazing')->exists());
    }

    public function test_requests_only_canonical_fabtcgmeta_format_codes(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->run();

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'format=CC-O'));
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'format=LL-O'));
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'Silver') && str_contains((string) $request->url(), 'Age-O'));

        $fabtcgmetaRequests = Http::recorded(fn ($request) => str_contains((string) $request->url(), 'api.fabtcgmeta.com'));
        $this->assertCount(3, $fabtcgmetaRequests);
    }

    public function test_requests_only_canonical_fablazing_format_slugs(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->run();

        foreach (['cc', 'll', 'sage', 'gage'] as $slug) {
            Http::assertSent(fn ($request) => str_ends_with((string) $request->url(), "/meta/{$slug}"));
        }

        $fablazingRequests = Http::recorded(fn ($request) => str_contains((string) $request->url(), 'fablazing.com'));
        $this->assertCount(4, $fablazingRequests);
    }

    public function test_never_requests_or_persists_non_canonical_formats(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->run();

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'BLZ-O'));
        Http::assertNotSent(fn ($request) => str_ends_with((string) $request->url(), '/meta/blitz'));
        $this->assertDatabaseMissing('meta_snapshots', ['format' => 'Blitz']);
        $this->assertDatabaseMissing('meta_snapshots', ['format' => 'Commoner']);
    }

    public function test_only_canonical_format_abbreviations_are_persisted(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->run();

        $canonical = Format::pluck('abbreviation')->all();
        $persistedFormats = MetaSnapshot::query()->distinct()->pluck('format')->all();

        foreach ($persistedFormats as $format) {
            $this->assertContains($format, $canonical);
        }

        $fabtcgmetaFormats = MetaSnapshot::where('source', 'fabtcgmeta')->distinct()->pluck('format')->sort()->values()->all();
        $this->assertEmpty(array_diff($fabtcgmetaFormats, ['CC', 'LL', 'SAGE']));

        $fablazingFormats = MetaSnapshot::where('source', 'fablazing')->distinct()->pluck('format')->sort()->values()->all();
        $this->assertEmpty(array_diff($fablazingFormats, ['CC', 'GAGE', 'LL', 'SAGE']));
    }

    public function test_resolves_canonical_formats_from_the_database_at_runtime(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        Format::where('abbreviation', 'GAGE')->delete();

        $this->artisan('data:ingest-meta')->run();

        Http::assertNotSent(fn ($request) => str_ends_with((string) $request->url(), '/meta/gage'));
        Http::assertSent(fn ($request) => str_ends_with((string) $request->url(), '/meta/cc'));
        $this->assertDatabaseMissing('meta_snapshots', ['format' => 'GAGE']);
    }

    public function test_maps_fabtcgmeta_fields_onto_meta_snapshot_columns(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->run();

        $card = Card::where('name', 'Arakni, Orb Weaver')->firstOrFail();
        $snapshot = MetaSnapshot::where('hero_id', $card->id)->where('source', 'fabtcgmeta')->where('format', 'CC')->firstOrFail();

        $fixtureEntry = collect($this->fabtcgmetaCcFixture()['heroPerformances'])->firstWhere('heroName', 'arakni_orb_weaver');

        $this->assertEqualsWithDelta($fixtureEntry['winrate'], (float) $snapshot->win_rate, 0.0001);
        $this->assertSame($fixtureEntry['sampleSize'], $snapshot->sample_size);
    }

    public function test_decodes_fablazing_remix_payload_and_maps_fields(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->run();

        $card = Card::where('name', 'Oscilio, Constella Intelligence')->firstOrFail();
        $snapshot = MetaSnapshot::where('hero_id', $card->id)->where('source', 'fablazing')->where('format', 'CC')->firstOrFail();

        $this->assertNotNull($snapshot->win_rate);
        $this->assertSame(9709, $snapshot->sample_size);
        $this->assertEqualsWithDelta(0.5609, (float) $snapshot->win_rate, 0.0001);
    }

    public function test_win_rate_is_persisted_as_provided_and_out_of_range_values_are_skipped(): void
    {
        $this->seedFormats();
        $card = Card::factory()->hero()->create(['name' => 'Arakni, Orb Weaver']);

        Http::fake([
            '*api.fabtcgmeta.com*format=CC-O*' => Http::response([
                'heroPerformances' => [
                    ['heroName' => 'arakni_orb_weaver', 'winrate' => 150.0, 'sampleSize' => 100],
                ],
            ]),
            '*api.fabtcgmeta.com*format=LL-O*' => Http::response($this->fabtcgmetaLlFixture()),
            '*api.fabtcgmeta.com*Silver*Age*' => Http::response(['heroPerformances' => []]),
            '*fablazing.com/meta/cc' => Http::response('<html><body></body></html>'),
            '*fablazing.com/meta/ll' => Http::response('<html><body></body></html>'),
            '*fablazing.com/meta/sage' => Http::response('<html><body></body></html>'),
            '*fablazing.com/meta/gage' => Http::response('<html><body></body></html>'),
        ]);

        Log::spy();

        $exitCode = $this->artisan('data:ingest-meta')->run();

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertDatabaseMissing('meta_snapshots', ['hero_id' => $card->id]);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'win rate'))->atLeast()->once();
    }

    public function test_empty_fabtcgmeta_format_response_produces_zero_rows_without_failure(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        $exitCode = $this->artisan('data:ingest-meta')->run();

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertDatabaseMissing('meta_snapshots', ['format' => 'LL', 'source' => 'fabtcgmeta']);
    }

    public function test_sources_are_independent_for_sparse_formats(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->run();

        $this->assertDatabaseHas('meta_snapshots', ['format' => 'LL', 'source' => 'fablazing']);
    }

    public function test_matches_fabtcgmeta_hero_slugs_to_hero_cards(): void
    {
        $this->seedFormats();
        $card = Card::factory()->hero()->create(['name' => 'Arakni, Orb Weaver']);
        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->run();

        $snapshot = MetaSnapshot::where('hero_id', $card->id)->where('source', 'fabtcgmeta')->first();
        $this->assertNotNull($snapshot);
    }

    public function test_matches_fablazing_hero_names_case_insensitively(): void
    {
        $this->seedFormats();
        $card = Card::factory()->hero()->create(['name' => 'oscilio, constella intelligence']);
        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->run();

        $snapshot = MetaSnapshot::where('hero_id', $card->id)->where('source', 'fablazing')->first();
        $this->assertNotNull($snapshot);
    }

    public function test_flags_and_skips_unmatched_hero_names(): void
    {
        $this->seedFormats();
        Card::factory()->hero()->create(['name' => 'Totally Unrelated Hero Name']);
        $this->fakeAllSources();

        Log::spy();
        $cardCountBefore = Card::count();

        $this->artisan('data:ingest-meta')->run();

        $this->assertSame(0, MetaSnapshot::count());
        $this->assertSame($cardCountBefore, Card::count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'No hero card match'))->atLeast()->once();
    }

    public function test_is_idempotent_across_reruns(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        $this->runFresh();
        $countAfterFirst = MetaSnapshot::count();

        $this->runFresh();
        $countAfterSecond = MetaSnapshot::count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_updates_existing_row_in_place_when_values_change(): void
    {
        $this->seedFormats();
        $card = Card::factory()->hero()->create(['name' => 'Arakni, Orb Weaver']);

        $snapshot = MetaSnapshot::factory()->create([
            'hero_id' => $card->id,
            'format' => 'CC',
            'source' => 'fabtcgmeta',
            'win_rate' => 0.1111,
        ]);

        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->run();

        $this->assertSame(1, MetaSnapshot::where('hero_id', $card->id)->where('format', 'CC')->where('source', 'fabtcgmeta')->count());

        $fresh = MetaSnapshot::find($snapshot->id);
        $this->assertNotEquals(0.1111, (float) $fresh->win_rate);
    }

    public function test_writes_distinct_rows_per_source_for_same_hero_and_format(): void
    {
        $this->seedFormats();
        Card::factory()->hero()->create(['name' => 'Oscilio, Constella Intelligence']);
        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->run();

        $card = Card::where('name', 'Oscilio, Constella Intelligence')->firstOrFail();
        $rows = MetaSnapshot::where('hero_id', $card->id)->where('format', 'CC')->get();

        $this->assertGreaterThanOrEqual(1, $rows->count());
        $this->assertSame($rows->pluck('source')->unique()->count(), $rows->count());
    }

    public function test_sends_honest_user_agent_to_every_source(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->run();

        Http::assertSent(fn ($request) => $request->header('User-Agent')[0] === 'YaFaBa-Data-Ingest/1.0 (+https://github.com/joshevensen/yafaba-api)');
        Http::assertNotSent(fn ($request) => (bool) preg_match('/Mozilla|Chrome|Safari|ClaudeBot|GPTBot|CCBot|Google-Extended/i', $request->header('User-Agent')[0] ?? ''));
    }

    public function test_continues_to_second_source_when_first_source_fails(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();

        Http::fake([
            '*api.fabtcgmeta.com*' => Http::response(null, 500),
            '*fablazing.com/meta/cc' => Http::response($this->fablazingHtml('cc')),
            '*fablazing.com/meta/ll' => Http::response($this->fablazingHtml('ll')),
            '*fablazing.com/meta/sage' => Http::response($this->fablazingHtml('sage')),
            '*fablazing.com/meta/gage' => Http::response($this->fablazingHtml('gage')),
        ]);

        $exitCode = $this->artisan('data:ingest-meta')->run();

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertTrue(MetaSnapshot::where('source', 'fablazing')->exists());
    }

    public function test_soft_fails_a_single_format_request_without_aborting_the_source(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();

        Http::fake([
            '*api.fabtcgmeta.com*format=CC-O*' => Http::response($this->fabtcgmetaCcFixture()),
            '*api.fabtcgmeta.com*format=LL-O*' => Http::response($this->fabtcgmetaLlFixture()),
            '*api.fabtcgmeta.com*Silver*Age*' => Http::response($this->fabtcgmetaSageFixture()),
            '*fablazing.com/meta/cc' => Http::response($this->fablazingHtml('cc')),
            '*fablazing.com/meta/ll' => Http::response($this->fablazingHtml('ll')),
            '*fablazing.com/meta/sage' => Http::response($this->fablazingHtml('sage')),
            '*fablazing.com/meta/gage' => Http::response(null, 500),
        ]);

        Log::spy();

        $exitCode = $this->artisan('data:ingest-meta')->run();

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertTrue(MetaSnapshot::where('source', 'fablazing')->where('format', 'CC')->exists());
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_returns_failure_when_all_sources_fail(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();

        Http::fake([
            '*api.fabtcgmeta.com*' => Http::response(null, 500),
            '*fablazing.com*' => Http::response(null, 500),
        ]);

        $exitCode = $this->artisan('data:ingest-meta')->run();

        $this->assertEquals(Command::FAILURE, $exitCode);
    }

    public function test_no_stray_outbound_requests(): void
    {
        $this->seedFormats();
        $this->seedKnownHeroCards();
        $this->fakeAllSources();

        $this->artisan('data:ingest-meta')->assertExitCode(0);
    }
}
