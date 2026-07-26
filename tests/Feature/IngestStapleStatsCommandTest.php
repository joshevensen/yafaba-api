<?php

namespace Tests\Feature;

use App\Console\Commands\IngestStapleStats;
use App\Models\Card;
use App\Models\CardType;
use App\Models\StapleStat;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class IngestStapleStatsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function heroPageFixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/fabrec-hero-page.html'));
    }

    private function malformedHeroPageFixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/fabrec-hero-page-malformed.html'));
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
        $command = app(IngestStapleStats::class);
        $command->setLaravel(app());

        return $command->run(new ArrayInput([]), new NullOutput);
    }

    public function test_creates_a_staple_stat_row_per_parsed_card_row(): void
    {
        $hero = Card::factory()->hero()->create(['name' => 'Dorinthea Ironsong']);
        Card::factory()->create(['name' => 'Braveforge Bracers']);
        Card::factory()->create(['name' => 'Glint the Quicksilver']);
        Card::factory()->create(['name' => 'Hit and Run (Blue)']);

        Http::fake([
            'https://fabrec.gg/hero/dorinthea-ironsong' => Http::response($this->heroPageFixture()),
        ]);

        $exitCode = Artisan::call('data:ingest-staples');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(3, StapleStat::count());

        foreach (StapleStat::all() as $stat) {
            $this->assertSame($hero->id, $stat->hero_id);
            $this->assertSame('fabrec.gg', $stat->source);
            $this->assertNotNull($stat->fetched_at);
        }
    }

    public function test_inclusion_rate_is_stored_as_a_fraction(): void
    {
        Card::factory()->hero()->create(['name' => 'Dorinthea Ironsong']);
        $bracers = Card::factory()->create(['name' => 'Braveforge Bracers']);
        Card::factory()->create(['name' => 'Glint the Quicksilver']);
        Card::factory()->create(['name' => 'Hit and Run (Blue)']);

        Http::fake([
            'https://fabrec.gg/hero/dorinthea-ironsong' => Http::response($this->heroPageFixture()),
        ]);

        Artisan::call('data:ingest-staples');

        $stat = StapleStat::where('card_id', $bracers->id)->firstOrFail();

        $this->assertSame(0.83, (float) $stat->inclusion_rate);
    }

    public function test_hero_page_url_is_built_from_the_slugified_hero_name(): void
    {
        Card::factory()->hero()->create(['name' => 'Dorinthea Ironsong']);
        Card::factory()->hero()->create(['name' => 'Vynnset, Iron Maiden']);

        Http::fake([
            'https://fabrec.gg/hero/dorinthea-ironsong' => Http::response($this->heroPageFixture()),
            'https://fabrec.gg/hero/vynnset-iron-maiden' => Http::response('<html><body></body></html>'),
        ]);

        Artisan::call('data:ingest-staples');

        Http::assertSent(fn ($request) => $request->url() === 'https://fabrec.gg/hero/dorinthea-ironsong');
        Http::assertSent(fn ($request) => $request->url() === 'https://fabrec.gg/hero/vynnset-iron-maiden');
    }

    public function test_all_cards_sharing_a_name_receive_a_row(): void
    {
        Card::factory()->hero()->create(['name' => 'Dorinthea Ironsong']);
        Card::factory()->create(['name' => 'Braveforge Bracers']);
        $first = Card::factory()->create(['name' => 'Glint the Quicksilver']);
        $second = Card::factory()->create(['name' => 'glint the quicksilver']);
        Card::factory()->create(['name' => 'Hit and Run (Blue)']);

        Http::fake([
            'https://fabrec.gg/hero/dorinthea-ironsong' => Http::response($this->heroPageFixture()),
        ]);

        Artisan::call('data:ingest-staples');

        $stats = StapleStat::whereIn('card_id', [$first->id, $second->id])->get();

        $this->assertCount(2, $stats);
        $this->assertSame($stats->pluck('inclusion_rate')->map(fn ($r) => (float) $r)->unique()->count(), 1);
    }

    public function test_unmatched_card_names_are_flagged_and_skipped(): void
    {
        Card::factory()->hero()->create(['name' => 'Dorinthea Ironsong']);
        Card::factory()->create(['name' => 'Braveforge Bracers']);
        Card::factory()->create(['name' => 'Glint the Quicksilver']);
        // Deliberately not creating a Card for 'Hit and Run (Blue)'.

        Http::fake([
            'https://fabrec.gg/hero/dorinthea-ironsong' => Http::response($this->heroPageFixture()),
        ]);

        Log::spy();

        Artisan::call('data:ingest-staples');

        $this->assertSame(2, StapleStat::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Unmatched fabrec card name'))
            ->atLeast()->once();
    }

    public function test_malformed_card_rows_are_flagged_and_skipped(): void
    {
        Card::factory()->hero()->create(['name' => 'Dorinthea Ironsong']);
        Card::factory()->create(['name' => 'Braveforge Bracers']);
        Card::factory()->create(['name' => 'Glint the Quicksilver']);

        Http::fake([
            'https://fabrec.gg/hero/dorinthea-ironsong' => Http::response($this->malformedHeroPageFixture()),
        ]);

        Log::spy();

        Artisan::call('data:ingest-staples');

        $this->assertSame(0, StapleStat::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Skipping malformed fabrec card row'))
            ->atLeast()->once();
    }

    public function test_hero_page_fetch_failure_is_flagged_and_run_still_succeeds(): void
    {
        Card::factory()->hero()->create(['name' => 'Dorinthea Ironsong']);
        Card::factory()->hero()->create(['name' => 'Vynnset, Iron Maiden']);
        Card::factory()->create(['name' => 'Braveforge Bracers']);
        Card::factory()->create(['name' => 'Glint the Quicksilver']);
        Card::factory()->create(['name' => 'Hit and Run (Blue)']);

        Http::fake([
            'https://fabrec.gg/hero/dorinthea-ironsong' => Http::response($this->heroPageFixture()),
            'https://fabrec.gg/hero/vynnset-iron-maiden' => Http::response('', 404),
        ]);

        Log::spy();

        $exitCode = Artisan::call('data:ingest-staples');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(3, StapleStat::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Failed to fetch fabrec hero page'))
            ->atLeast()->once();
    }

    public function test_command_fails_when_no_hero_cards_exist(): void
    {
        CardType::create(['name' => 'hero', 'display_order' => 1]);

        $exitCode = Artisan::call('data:ingest-staples');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertSame(0, StapleStat::count());
    }

    public function test_command_fails_when_no_hero_card_type_exists(): void
    {
        $exitCode = Artisan::call('data:ingest-staples');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertSame(0, StapleStat::count());
        Http::assertNothingSent();
    }

    public function test_rerun_overwrites_existing_rows_instead_of_duplicating(): void
    {
        Card::factory()->hero()->create(['name' => 'Dorinthea Ironsong']);
        Card::factory()->create(['name' => 'Braveforge Bracers']);
        Card::factory()->create(['name' => 'Glint the Quicksilver']);
        Card::factory()->create(['name' => 'Hit and Run (Blue)']);

        Http::fake([
            'https://fabrec.gg/hero/dorinthea-ironsong' => Http::response($this->heroPageFixture()),
        ]);

        $this->assertSame(Command::SUCCESS, $this->runFresh());
        $countAfterFirst = StapleStat::count();

        $mutated = StapleStat::whereHas('card', fn ($q) => $q->where('name', 'Braveforge Bracers'))->firstOrFail();
        $mutated->inclusion_rate = 0.1234;
        $mutated->save();

        Http::fake([
            'https://fabrec.gg/hero/dorinthea-ironsong' => Http::response($this->heroPageFixture()),
        ]);

        $this->assertSame(Command::SUCCESS, $this->runFresh());

        $this->assertSame($countAfterFirst, StapleStat::count());

        $mutated->refresh();
        $this->assertSame(0.83, (float) $mutated->inclusion_rate);
    }

    public function test_requests_send_a_browser_user_agent(): void
    {
        Card::factory()->hero()->create(['name' => 'Dorinthea Ironsong']);
        Card::factory()->create(['name' => 'Braveforge Bracers']);
        Card::factory()->create(['name' => 'Glint the Quicksilver']);
        Card::factory()->create(['name' => 'Hit and Run (Blue)']);

        Http::fake([
            'https://fabrec.gg/hero/dorinthea-ironsong' => Http::response($this->heroPageFixture()),
        ]);

        Artisan::call('data:ingest-staples');

        Http::assertSent(fn ($request) => str_contains($request->header('User-Agent')[0] ?? '', 'Mozilla/5.0'));
    }

    public function test_base_url_option_overrides_the_default(): void
    {
        Card::factory()->hero()->create(['name' => 'Dorinthea Ironsong']);
        Card::factory()->create(['name' => 'Braveforge Bracers']);
        Card::factory()->create(['name' => 'Glint the Quicksilver']);
        Card::factory()->create(['name' => 'Hit and Run (Blue)']);

        Http::fake([
            'https://example.test/hero/dorinthea-ironsong' => Http::response($this->heroPageFixture()),
        ]);

        $this->artisan('data:ingest-staples', ['--base-url' => 'https://example.test'])->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->url() === 'https://example.test/hero/dorinthea-ironsong');
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'fabrec.gg'));
    }

    public function test_hero_id_references_the_hero_card_row(): void
    {
        Card::factory()->hero()->create(['name' => 'Dorinthea Ironsong']);
        Card::factory()->create(['name' => 'Braveforge Bracers']);
        Card::factory()->create(['name' => 'Glint the Quicksilver']);
        Card::factory()->create(['name' => 'Hit and Run (Blue)']);

        Http::fake([
            'https://fabrec.gg/hero/dorinthea-ironsong' => Http::response($this->heroPageFixture()),
        ]);

        Artisan::call('data:ingest-staples');

        $heroTypeId = CardType::where('name', 'hero')->value('id');

        foreach (StapleStat::all() as $stat) {
            $heroCard = Card::findOrFail($stat->hero_id);
            $this->assertSame($heroTypeId, $heroCard->card_type_id);
        }
    }
}
