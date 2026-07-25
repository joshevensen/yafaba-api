<?php

namespace Tests\Feature;

use App\Console\Commands\IngestErrataBulletins;
use App\Models\Card;
use App\Models\ErrataBulletin;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class IngestErrataBulletinsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX_URL = 'https://fabtcg.com/rules-and-policy-center/errata-bulletins/';

    private const ARTICLE_URL = 'https://fabtcg.com/articles/errata-bulletin-10/';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function indexFixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/errata-bulletin-index.html'));
    }

    private function articleFixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/errata-bulletin-article.html'));
    }

    private function legacyFixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/errata-bulletin-legacy.html'));
    }

    /**
     * Fakes the standard upstream: the bulletins index, the bulletin #10 article,
     * and the legacy bulletin #2 page. More specific URL patterns are registered
     * before broad ones so they win, since Http::fake resolves patterns in
     * registration order and the first matching stub always wins.
     */
    private function fakeStandardUpstream(): void
    {
        Http::fake([
            self::ARTICLE_URL => Http::response($this->articleFixture()),
            self::INDEX_URL => Http::response($this->indexFixture()),
            'https://legacy.fabtcg.com/*' => Http::response($this->legacyFixture()),
        ]);
    }

    /**
     * Resolves and runs a brand new command instance from the container, the
     * same way each separate `php artisan` invocation would in production.
     * Laravel's console Application reuses a single registered Command
     * instance across repeated `$this->artisan()` calls within one test, so
     * the command's per-run counters would otherwise leak between what are
     * meant to be two independent runs.
     */
    private function runIngestErrataFresh(): int
    {
        $command = app(IngestErrataBulletins::class);
        $command->setLaravel(app());

        return $command->run(new ArrayInput([]), new NullOutput);
    }

    public function test_creates_a_bulletin_row_with_correct_field_mapping(): void
    {
        $this->fakeStandardUpstream();

        $this->assertSame(Command::SUCCESS, Artisan::call('data:ingest-errata'));

        // Both counters live on one summary line, so the output is asserted
        // directly rather than through two `expectsOutputToContain` calls,
        // which Mockery would only satisfy one of for a single write.
        $output = Artisan::output();
        $this->assertStringContainsString('1 created', $output);
        $this->assertStringContainsString('1 flagged', $output);

        $bulletin = ErrataBulletin::where('bulletin_number', '10')->first();

        $this->assertNotNull($bulletin);
        $this->assertSame('10', $bulletin->bulletin_number);
        $this->assertSame(self::ARTICLE_URL, $bulletin->url);
        $this->assertSame('2026-03-09', $bulletin->published_date->toDateString());

        $this->assertStringContainsString('Cheating Scoundrel', $bulletin->content);
        $this->assertStringContainsString('Now reads: sample errata body text.', $bulletin->content);
        $this->assertStringNotContainsString('entry-content', $bulletin->content);
        $this->assertStringNotContainsString('Outside the content div', $bulletin->content);

        $this->assertIsArray($bulletin->affected_card_ids);
        $this->assertInstanceOf(Carbon::class, $bulletin->cached_at);
    }

    public function test_affected_card_ids_match_headings_case_insensitively_and_ignore_non_card_headings(): void
    {
        $this->fakeStandardUpstream();

        $scoundrel = Card::factory()->create(['name' => 'Cheating Scoundrel']);
        $sinkBelow = Card::factory()->create(['name' => 'sink below']);
        $control = Card::factory()->create(['name' => 'Totally Unrelated Card']);

        $this->artisan('data:ingest-errata')->assertExitCode(0);

        $affected = ErrataBulletin::where('bulletin_number', '10')->firstOrFail()->affected_card_ids;

        $this->assertCount(2, $affected);
        $this->assertContains($scoundrel->id, $affected);
        $this->assertContains($sinkBelow->id, $affected);
        $this->assertNotContains($control->id, $affected);
    }

    public function test_all_cards_sharing_a_heading_name_are_linked(): void
    {
        $this->fakeStandardUpstream();

        $first = Card::factory()->create(['name' => 'Sink Below']);
        $second = Card::factory()->create(['name' => 'Sink Below']);

        $this->artisan('data:ingest-errata')->assertExitCode(0);

        $affected = ErrataBulletin::where('bulletin_number', '10')->firstOrFail()->affected_card_ids;

        $this->assertContains($first->id, $affected);
        $this->assertContains($second->id, $affected);
    }

    public function test_bulletin_with_no_matching_headings_stores_an_empty_array(): void
    {
        $this->fakeStandardUpstream();

        $this->assertSame(0, Card::count());

        $this->artisan('data:ingest-errata')->assertExitCode(0);

        $bulletin = ErrataBulletin::where('bulletin_number', '10')->firstOrFail();

        $this->assertSame([], $bulletin->affected_card_ids);
    }

    public function test_legacy_bulletin_without_expected_structure_is_flagged_and_skipped(): void
    {
        Log::spy();

        $this->fakeStandardUpstream();

        $this->artisan('data:ingest-errata')->assertExitCode(0);

        $this->assertFalse(ErrataBulletin::where('bulletin_number', '2')->exists());
        $this->assertSame(1, ErrataBulletin::count());

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_article_fetch_failure_is_flagged_and_run_still_succeeds(): void
    {
        Log::spy();

        Http::fake([
            self::ARTICLE_URL => Http::response('', 500),
            self::INDEX_URL => Http::response($this->indexFixture()),
            'https://legacy.fabtcg.com/*' => Http::response($this->legacyFixture()),
        ]);

        $this->artisan('data:ingest-errata')->assertExitCode(0);

        $this->assertFalse(ErrataBulletin::where('bulletin_number', '10')->exists());

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_idempotent_rerun_does_not_refetch_cached_bulletin_articles(): void
    {
        $this->fakeStandardUpstream();

        $this->assertSame(Command::SUCCESS, $this->runIngestErrataFresh());

        $countAfterFirst = ErrataBulletin::count();
        $this->assertSame(1, $countAfterFirst);

        $this->assertSame(Command::SUCCESS, $this->runIngestErrataFresh());

        $this->assertSame($countAfterFirst, ErrataBulletin::count());

        $articleRequests = count(Http::recorded(
            fn ($request) => str_contains((string) $request->url(), 'errata-bulletin-10'),
        ));

        $this->assertSame(1, $articleRequests);
    }

    public function test_existing_bulletin_row_is_never_modified_on_rerun(): void
    {
        $this->fakeStandardUpstream();

        $existing = ErrataBulletin::factory()->create([
            'bulletin_number' => '10',
            'content' => '<p>sentinel</p>',
        ]);

        $this->artisan('data:ingest-errata')->assertExitCode(0);

        $existing->refresh();

        $this->assertSame('<p>sentinel</p>', $existing->content);

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'errata-bulletin-10'));
    }

    public function test_index_fetch_failure_aborts_with_failure_exit_code(): void
    {
        Http::fake([
            self::INDEX_URL => Http::response('', 500),
        ]);

        $exitCode = Artisan::call('data:ingest-errata');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertSame(0, ErrataBulletin::count());
    }

    public function test_empty_index_aborts_with_failure_exit_code(): void
    {
        Http::fake([
            self::INDEX_URL => Http::response('<html><body><p>nothing here</p></body></html>'),
        ]);

        $exitCode = Artisan::call('data:ingest-errata');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertSame(0, ErrataBulletin::count());
    }

    public function test_index_entry_without_a_parsable_bulletin_number_is_flagged_and_skipped(): void
    {
        Log::spy();

        $index = <<<'HTML'
        <html>
        <head><title>Errata Bulletins</title></head>
        <body>
            <div>
                <a class="fl-link-card-ssr" href="https://fabtcg.com/articles/errata-bulletin-10/">
                    <div class="fl-link-card-ssr-content"><h3>Errata Bulletin #10</h3></div>
                </a>
            </div>
            <div>
                <a class="fl-link-card-ssr" href="https://fabtcg.com/articles/errata-bulletin-unnumbered/">
                    <div class="fl-link-card-ssr-content"><h3>Errata Bulletin</h3></div>
                </a>
            </div>
        </body>
        </html>
        HTML;

        Http::fake([
            self::ARTICLE_URL => Http::response($this->articleFixture()),
            self::INDEX_URL => Http::response($index),
        ]);

        $this->artisan('data:ingest-errata')->assertExitCode(0);

        $this->assertSame(1, ErrataBulletin::count());
        $this->assertTrue(ErrataBulletin::where('bulletin_number', '10')->exists());

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_requests_send_a_browser_user_agent(): void
    {
        $this->fakeStandardUpstream();

        $this->artisan('data:ingest-errata')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->url() === self::INDEX_URL
            && str_contains($request->header('User-Agent')[0], 'Mozilla/5.0'));

        Http::assertSent(fn ($request) => $request->url() === self::ARTICLE_URL
            && str_contains($request->header('User-Agent')[0], 'Mozilla/5.0'));
    }
}
