<?php

namespace Tests\Feature;

use App\Console\Commands\IngestHeroSelectionMetadata;
use App\Models\Card;
use App\Models\CardLegality;
use App\Models\CardType;
use App\Models\Format;
use App\Models\Hero;
use App\Models\HeroProfile;
use Database\Seeders\FormatSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class IngestHeroSelectionMetadataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function heroSelectionFixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/fabtcg-hero-selection.json')), true);
    }

    private function fakeHeroSelectionApi(): void
    {
        Http::fake([
            'https://fabtcg.com/api/fab/v2/heroes/*' => Http::response($this->heroSelectionFixture()),
        ]);
    }

    /**
     * Seed a strictly 1:1:1 hero Card/Hero/HeroProfile triple, matching
     * IngestFabCubeCards::seedHeroFor()'s convention.
     *
     * @return array{card: Card, hero: Hero, profile: HeroProfile}
     */
    private function seedHeroCard(string $name): array
    {
        $heroTypeId = CardType::firstOrCreate(['name' => 'hero'], ['display_order' => 1])->id;

        $card = Card::factory()->create(['name' => $name, 'card_type_id' => $heroTypeId]);

        $hero = Hero::create(['name' => $name]);
        $profile = HeroProfile::create(['hero_id' => $hero->id, 'label' => $name]);

        $card->hero_profile_id = $profile->id;
        $card->save();

        return ['card' => $card->fresh(), 'hero' => $hero, 'profile' => $profile];
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
        $command = app(IngestHeroSelectionMetadata::class);
        $command->setLaravel(app());

        return $command->run(new ArrayInput([]), new NullOutput);
    }

    public function test_populates_playstyle_tags_from_the_payload(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('Dorinthea Ironsong');
        $this->fakeHeroSelectionApi();

        $exitCode = Artisan::call('data:ingest-hero-selection');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(['aggressive', 'midrange'], $seeded['profile']->fresh()->playstyle_tags);
    }

    public function test_populates_hero_lore_with_html_stripped_and_whitespace_normalized(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('Dorinthea Ironsong');
        $this->fakeHeroSelectionApi();

        Artisan::call('data:ingest-hero-selection');

        $this->assertSame('Dorinthea Ironsong is a Warrior of Solana.', $seeded['hero']->fresh()->lore);
    }

    public function test_writes_card_legality_for_the_three_mapped_formats(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('Dorinthea Ironsong');
        $this->fakeHeroSelectionApi();

        Artisan::call('data:ingest-hero-selection');

        $ccId = Format::where('abbreviation', 'CC')->value('id');
        $llId = Format::where('abbreviation', 'LL')->value('id');
        $sageId = Format::where('abbreviation', 'SAGE')->value('id');

        $this->assertSame('legal', CardLegality::where('card_id', $seeded['card']->id)->where('format_id', $ccId)->value('status'));
        $this->assertSame('not_legal', CardLegality::where('card_id', $seeded['card']->id)->where('format_id', $llId)->value('status'));
        $this->assertSame('legal', CardLegality::where('card_id', $seeded['card']->id)->where('format_id', $sageId)->value('status'));
    }

    public function test_writes_no_legality_rows_for_unmapped_format_keys(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('Dorinthea Ironsong');
        $this->fakeHeroSelectionApi();

        Artisan::call('data:ingest-hero-selection');

        $this->assertSame(3, CardLegality::where('card_id', $seeded['card']->id)->count());
        $this->assertSame(4, Format::count());
    }

    public function test_unmapped_format_keys_are_flagged_once_per_run(): void
    {
        $this->seed(FormatSeeder::class);
        $this->seedHeroCard('Dorinthea Ironsong');
        $this->seedHeroCard('Kano Dracai of Aether');
        $this->seedHeroCard('Uzuri');
        $this->seedHeroCard('Vynnset Iron Maiden');
        $this->fakeHeroSelectionApi();

        Log::spy();

        Artisan::call('data:ingest-hero-selection');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Unmapped fabtcg format key'))
            ->times(3);
    }

    public function test_unmatched_hero_names_are_flagged_and_skipped(): void
    {
        $this->seed(FormatSeeder::class);
        $this->seedHeroCard('Dorinthea Ironsong');
        // Deliberately not creating a Card for "Melody Sing-Song".
        $this->fakeHeroSelectionApi();

        Log::spy();

        $exitCode = Artisan::call('data:ingest-hero-selection');

        $this->assertSame(Command::SUCCESS, $exitCode);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Unmatched fabtcg hero name'))
            ->atLeast()->once();
    }

    public function test_resolves_heroes_with_an_empty_subtitle(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('Uzuri');
        $this->fakeHeroSelectionApi();

        Artisan::call('data:ingest-hero-selection');

        $this->assertSame(['control'], $seeded['profile']->fresh()->playstyle_tags);
    }

    public function test_matches_hero_names_case_insensitively_and_only_against_hero_cards(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('dorinthea ironsong');
        $nonHero = Card::factory()->create(['name' => 'Dorinthea Ironsong']);
        $this->fakeHeroSelectionApi();

        Artisan::call('data:ingest-hero-selection');

        $this->assertSame(['aggressive', 'midrange'], $seeded['profile']->fresh()->playstyle_tags);
        $this->assertSame(0, CardLegality::where('card_id', $nonHero->id)->count());
    }

    public function test_empty_playstyle_leaves_existing_tags_untouched_and_is_flagged(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('Vynnset Iron Maiden');
        $seeded['profile']->update(['playstyle_tags' => ['curated']]);
        $this->fakeHeroSelectionApi();

        Log::spy();

        Artisan::call('data:ingest-hero-selection');

        $this->assertSame(['curated'], $seeded['profile']->fresh()->playstyle_tags);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Missing fabtcg playstyle tags'))
            ->atLeast()->once();
    }

    public function test_empty_bio_leaves_existing_lore_untouched_and_is_flagged(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('Vynnset Iron Maiden');
        $seeded['hero']->update(['lore' => 'curated lore']);
        $this->fakeHeroSelectionApi();

        Log::spy();

        Artisan::call('data:ingest-hero-selection');

        $this->assertSame('curated lore', $seeded['hero']->fresh()->lore);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Missing fabtcg hero bio'))
            ->atLeast()->once();
    }

    public function test_unrecognized_legality_status_is_flagged_and_skipped(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('Vynnset Iron Maiden');
        $this->fakeHeroSelectionApi();

        Log::spy();

        $exitCode = Artisan::call('data:ingest-hero-selection');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(0, CardLegality::where('card_id', $seeded['card']->id)->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Unrecognized fabtcg legality status'))
            ->atLeast()->once();
    }

    public function test_overwrites_existing_community_sourced_legality(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('Kano Dracai of Aether');
        $ccId = Format::where('abbreviation', 'CC')->value('id');

        CardLegality::create(['card_id' => $seeded['card']->id, 'format_id' => $ccId, 'status' => 'banned']);

        $this->fakeHeroSelectionApi();

        Artisan::call('data:ingest-hero-selection');

        $this->assertSame('not_legal', CardLegality::where('card_id', $seeded['card']->id)->where('format_id', $ccId)->value('status'));
        $this->assertSame(1, CardLegality::where('card_id', $seeded['card']->id)->where('format_id', $ccId)->count());
    }

    public function test_missing_format_rows_are_flagged_and_run_still_succeeds(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('Dorinthea Ironsong');
        Format::where('abbreviation', 'SAGE')->delete();
        $this->fakeHeroSelectionApi();

        Log::spy();

        $exitCode = Artisan::call('data:ingest-hero-selection');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(['aggressive', 'midrange'], $seeded['profile']->fresh()->playstyle_tags);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Skipping legality sync for unseeded format'))
            ->atLeast()->once();
    }

    public function test_fails_when_no_hero_cards_exist(): void
    {
        $this->seed(FormatSeeder::class);
        CardType::create(['name' => 'hero', 'display_order' => 1]);

        $exitCode = Artisan::call('data:ingest-hero-selection');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        Http::assertNothingSent();
    }

    public function test_fails_when_no_hero_card_type_exists(): void
    {
        $this->seed(FormatSeeder::class);

        $exitCode = Artisan::call('data:ingest-hero-selection');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        Http::assertNothingSent();
    }

    public function test_fetch_failure_fails_the_run_and_writes_nothing(): void
    {
        $this->seed(FormatSeeder::class);
        $this->seedHeroCard('Dorinthea Ironsong');

        Http::fake([
            'https://fabtcg.com/api/fab/v2/heroes/*' => Http::response('', 500),
        ]);

        $exitCode = Artisan::call('data:ingest-hero-selection');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertSame(0, CardLegality::count());
        $this->assertTrue(HeroProfile::whereNotNull('playstyle_tags')->doesntExist());
    }

    public function test_malformed_payload_fails_the_run(): void
    {
        $this->seed(FormatSeeder::class);
        $this->seedHeroCard('Dorinthea Ironsong');

        Http::fake([
            'https://fabtcg.com/api/fab/v2/heroes/*' => Http::response(['filters' => []]),
        ]);

        $exitCode = Artisan::call('data:ingest-hero-selection');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertSame(0, CardLegality::count());
    }

    public function test_rerun_is_idempotent_and_restores_source_values(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('Dorinthea Ironsong');
        $this->fakeHeroSelectionApi();

        $this->assertSame(Command::SUCCESS, $this->runFresh());
        $countAfterFirst = CardLegality::where('card_id', $seeded['card']->id)->count();

        $ccId = Format::where('abbreviation', 'CC')->value('id');
        CardLegality::where('card_id', $seeded['card']->id)->where('format_id', $ccId)->update(['status' => 'banned']);
        $seeded['profile']->update(['playstyle_tags' => ['stale']]);

        $this->fakeHeroSelectionApi();

        $this->assertSame(Command::SUCCESS, $this->runFresh());

        $this->assertSame($countAfterFirst, CardLegality::where('card_id', $seeded['card']->id)->count());
        $this->assertSame('legal', CardLegality::where('card_id', $seeded['card']->id)->where('format_id', $ccId)->value('status'));
        $this->assertSame(['aggressive', 'midrange'], $seeded['profile']->fresh()->playstyle_tags);
    }

    public function test_requests_the_hero_api_with_the_project_user_agent(): void
    {
        $this->seed(FormatSeeder::class);
        $this->seedHeroCard('Dorinthea Ironsong');
        $this->fakeHeroSelectionApi();

        Artisan::call('data:ingest-hero-selection');

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), 'fabtcg.com/api/fab/v2/heroes')
                && str_contains($request->header('User-Agent')[0] ?? '', 'YaFaBa-Data-Ingest');
        });
    }

    public function test_api_url_option_overrides_the_default(): void
    {
        $this->seed(FormatSeeder::class);
        $this->seedHeroCard('Dorinthea Ironsong');

        Http::fake([
            'https://example.test/heroes' => Http::response($this->heroSelectionFixture()),
        ]);

        $this->artisan('data:ingest-hero-selection', ['--api-url' => 'https://example.test/heroes'])->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->url() === 'https://example.test/heroes');
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'fabtcg.com'));
    }

    public function test_prints_a_summary_line(): void
    {
        $this->seed(FormatSeeder::class);
        $this->seedHeroCard('Dorinthea Ironsong');
        $this->fakeHeroSelectionApi();

        $this->artisan('data:ingest-hero-selection')
            ->expectsOutputToContain('Hero selection ingest complete:')
            ->assertExitCode(0);
    }

    public function test_does_not_touch_curation_owned_fields(): void
    {
        $this->seed(FormatSeeder::class);
        $seeded = $this->seedHeroCard('Dorinthea Ironsong');
        $this->fakeHeroSelectionApi();

        Artisan::call('data:ingest-hero-selection');

        $profile = $seeded['profile']->fresh();
        $this->assertNull($profile->complexity_score);
        $this->assertNull($profile->complexity_rating);
        $this->assertNull($profile->pattern_summary);
        $this->assertNull($profile->pitch_lean);
        $this->assertNull($profile->notes);
    }
}
