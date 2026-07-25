<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CardLegality;
use App\Models\CardType;
use App\Models\Hero;
use App\Models\HeroProfile;
use App\Models\Keyword;
use Database\Seeders\FormatSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class IngestFabCubeCardsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->seed(FormatSeeder::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fixtureRecords(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/fab-cube-cards.json')), true);
    }

    private function fakeUpstream(mixed $payload): void
    {
        Http::fake([
            '*' => Http::response($payload),
        ]);
    }

    /**
     * Registers a single upstream fake whose response body can be swapped mid-test
     * by mutating the returned holder's `payload` property. A second call to
     * Http::fake('*') would not take effect, since Laravel resolves fakes in
     * registration order and the first matching `*` stub always wins.
     */
    private function fakeUpstreamMutable(mixed $initialPayload): \stdClass
    {
        $state = new \stdClass;
        $state->payload = $initialPayload;

        Http::fake([
            '*' => fn () => Http::response($state->payload),
        ]);

        return $state;
    }

    /**
     * A minimal, well-formed upstream record with sensible defaults, for tests
     * that only care about one or two fields.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseRecord(array $overrides = []): array
    {
        return array_merge([
            'unique_id' => 'base-'.($overrides['name'] ?? 'record'),
            'name' => 'Base Record',
            'color' => '',
            'pitch' => '',
            'cost' => '',
            'power' => '',
            'defense' => '',
            'health' => '',
            'intelligence' => '',
            'arcane' => '',
            'types' => ['Generic', 'Action'],
            'traits' => [],
            'card_keywords' => [],
            'abilities_and_effects' => [],
            'ability_and_effect_keywords' => [],
            'granted_keywords' => [],
            'removed_keywords' => [],
            'interacts_with_keywords' => [],
            'functional_text' => '',
            'functional_text_plain' => '',
            'type_text' => '',
            'played_horizontally' => false,
            'blitz_legal' => false,
            'cc_legal' => false,
            'commoner_legal' => false,
            'll_legal' => false,
            'silver_age_legal' => false,
            'blitz_living_legend' => false,
            'cc_living_legend' => false,
            'blitz_banned' => false,
            'cc_banned' => false,
            'commoner_banned' => false,
            'll_banned' => false,
            'silver_age_banned' => false,
            'upf_banned' => false,
            'blitz_suspended' => false,
            'cc_suspended' => false,
            'commoner_suspended' => false,
            'll_restricted' => false,
            'printings' => [],
        ], $overrides);
    }

    public function test_ingest_creates_expected_row_counts_and_card_attributes(): void
    {
        $this->fakeUpstream($this->fixtureRecords());

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $this->assertDatabaseCount('cards', 7);
        $this->assertDatabaseCount('card_types', 9);
        $this->assertDatabaseCount('heroes', 2);
        $this->assertDatabaseCount('hero_profiles', 2);

        $this->assertGreaterThan(0, DB::table('card_classes')->count());
        $this->assertGreaterThan(0, DB::table('card_talents')->count());
        $this->assertGreaterThan(0, DB::table('card_keywords')->count());
        $this->assertGreaterThan(0, CardLegality::count());

        $this->assertDatabaseHas('cards', [
            'name' => 'Veiled Aegis',
            'pitch_value' => 1,
            'cost' => '8',
            'power' => null,
            'defense' => 3,
            'source_id' => 'fixture-001',
        ]);

        $card = Card::where('source_id', 'fixture-001')->firstOrFail();
        $this->assertNotNull($card->source_hash);
    }

    public function test_card_types_seeded_with_exact_nine_names(): void
    {
        $this->fakeUpstream($this->fixtureRecords());

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $expected = [
            'action', 'attack_reaction', 'defense_reaction', 'equipment', 'hero',
            'instant', 'other', 'resource_token', 'weapon',
        ];

        $this->assertSame($expected, CardType::pluck('name')->sort()->values()->all());
        $this->assertDatabaseCount('card_types', 9);
    }

    public function test_card_type_classification_is_correct_per_record(): void
    {
        $this->fakeUpstream($this->fixtureRecords());

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $this->assertSame('action', Card::where('source_id', 'fixture-001')->firstOrFail()->cardType->name);
        $this->assertSame('hero', Card::where('source_id', 'fixture-002')->firstOrFail()->cardType->name);
        $this->assertSame('hero', Card::where('source_id', 'fixture-003')->firstOrFail()->cardType->name);
        $this->assertSame('weapon', Card::where('source_id', 'fixture-004')->firstOrFail()->cardType->name);
        $this->assertSame('other', Card::where('source_id', 'fixture-005')->firstOrFail()->cardType->name);
    }

    public function test_unrecognized_type_logs_warning_and_still_succeeds(): void
    {
        Log::spy();

        $this->fakeUpstream($this->fixtureRecords());

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        Log::shouldHaveReceived('warning')->with('Unrecognized card type', Mockery::any())->atLeast()->once();

        $this->assertTrue(Card::where('source_id', 'fixture-005')->exists());
    }

    public function test_record_missing_unique_id_is_skipped_and_flagged(): void
    {
        Log::spy();

        $records = [
            $this->baseRecord(['unique_id' => null, 'name' => 'No Id Card']),
            $this->baseRecord(['unique_id' => '', 'name' => 'Empty Id Card']),
            $this->baseRecord(['unique_id' => 'has-id', 'name' => 'Has Id Card']),
        ];
        $this->fakeUpstream($records);

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        Log::shouldHaveReceived('warning')->with('Skipping card with missing unique_id', Mockery::any())->twice();

        $this->assertDatabaseCount('cards', 1);
        $this->assertFalse(Card::where('name', 'No Id Card')->exists());
        $this->assertFalse(Card::where('name', 'Empty Id Card')->exists());
        $this->assertTrue(Card::where('source_id', 'has-id')->exists());
    }

    public function test_card_type_classification_covers_every_branch(): void
    {
        $records = [
            $this->baseRecord(['unique_id' => 'branch-equipment', 'types' => ['Mechanologist', 'Equipment', 'Head']]),
            $this->baseRecord(['unique_id' => 'branch-attack-reaction', 'types' => ['Warrior', 'Attack Reaction']]),
            $this->baseRecord(['unique_id' => 'branch-defense-reaction', 'types' => ['Wizard', 'Defense Reaction']]),
            $this->baseRecord(['unique_id' => 'branch-resource', 'types' => ['Generic', 'Resource', 'Gem']]),
            $this->baseRecord(['unique_id' => 'branch-token', 'types' => ['Generic', 'Token', 'Aura']]),
            $this->baseRecord(['unique_id' => 'branch-demi-hero', 'name' => 'Demi Hero Card', 'types' => ['Assassin', 'Demi-Hero']]),
        ];
        $this->fakeUpstream($records);

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $this->assertSame('equipment', Card::where('source_id', 'branch-equipment')->firstOrFail()->cardType->name);
        $this->assertSame('attack_reaction', Card::where('source_id', 'branch-attack-reaction')->firstOrFail()->cardType->name);
        $this->assertSame('defense_reaction', Card::where('source_id', 'branch-defense-reaction')->firstOrFail()->cardType->name);
        $this->assertSame('resource_token', Card::where('source_id', 'branch-resource')->firstOrFail()->cardType->name);
        $this->assertSame('resource_token', Card::where('source_id', 'branch-token')->firstOrFail()->cardType->name);
        $this->assertSame('hero', Card::where('source_id', 'branch-demi-hero')->firstOrFail()->cardType->name);
    }

    public function test_ignorable_subtype_tokens_do_not_become_classes_or_talents(): void
    {
        $this->fakeUpstream($this->fixtureRecords());

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $card = Card::where('source_id', 'fixture-004')->firstOrFail();

        $this->assertSame(1, $card->classes()->count());
        $this->assertSame('Warrior', $card->classes()->first()->name);
        $this->assertSame(0, $card->talents()->count());
    }

    public function test_functional_text_uses_plain_variant(): void
    {
        $this->fakeUpstream($this->fixtureRecords());

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $card = Card::where('source_id', 'fixture-001')->firstOrFail();

        $this->assertStringContainsString('Ward 10', $card->functional_text);
        $this->assertStringNotContainsString('**', $card->functional_text);
    }

    public function test_keyword_normalization_strips_trailing_count(): void
    {
        $this->fakeUpstream($this->fixtureRecords());

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $keyword = Keyword::where('name', 'Ward')->first();
        $this->assertNotNull($keyword);

        $card = Card::where('source_id', 'fixture-001')->firstOrFail();
        $this->assertTrue($card->keywords()->where('keywords.id', $keyword->id)->exists());
    }

    public function test_legality_precedence_and_exact_format_scope(): void
    {
        $this->fakeUpstream($this->fixtureRecords());

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $card1 = Card::where('source_id', 'fixture-001')->firstOrFail();
        $this->assertSame(2, $card1->legalities()->count());
        $this->assertSame(2, $card1->legalities()->where('status', 'legal')->count());

        $card6 = Card::where('source_id', 'fixture-006')->firstOrFail();
        $this->assertTrue($card6->legalities()->whereHas('format', fn ($q) => $q->where('abbreviation', 'CC'))->where('status', 'banned')->exists());

        $card7 = Card::where('source_id', 'fixture-007')->firstOrFail();
        $this->assertTrue($card7->legalities()->whereHas('format', fn ($q) => $q->where('abbreviation', 'SAGE'))->where('status', 'legal')->exists());

        $this->assertSame(
            0,
            CardLegality::whereDoesntHave('format', fn ($q) => $q->whereIn('abbreviation', ['SAGE', 'CC']))->count(),
        );
    }

    public function test_legality_row_is_deleted_when_a_format_regresses_to_no_flags(): void
    {
        $legalRecord = $this->baseRecord(['unique_id' => 'legality-regression', 'cc_legal' => true]);
        $state = $this->fakeUpstreamMutable([$legalRecord]);

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $card = Card::where('source_id', 'legality-regression')->firstOrFail();
        $this->assertTrue($card->legalities()->whereHas('format', fn ($q) => $q->where('abbreviation', 'CC'))->where('status', 'legal')->exists());

        $revokedRecord = $legalRecord;
        $revokedRecord['cc_legal'] = false;
        $state->payload = [$revokedRecord];

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $this->assertFalse($card->legalities()->whereHas('format', fn ($q) => $q->where('abbreviation', 'CC'))->exists());
        $this->assertSame(0, $card->legalities()->count());
    }

    public function test_hero_seeding_is_strictly_one_to_one_to_one(): void
    {
        $this->fakeUpstream($this->fixtureRecords());

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $this->assertDatabaseCount('heroes', 2);
        $this->assertDatabaseCount('hero_profiles', 2);

        $heroNames = Hero::pluck('name')->sort()->values()->all();
        $this->assertSame(['Dorinthea', 'Dorinthea Ironsong'], $heroNames);

        $heroIds = Hero::pluck('id')->all();
        $this->assertCount(2, array_unique($heroIds));

        $card2 = Card::where('source_id', 'fixture-002')->firstOrFail();
        $card3 = Card::where('source_id', 'fixture-003')->firstOrFail();

        $this->assertNotNull($card2->hero_profile_id);
        $this->assertNotNull($card3->hero_profile_id);
        $this->assertNotSame($card2->hero_profile_id, $card3->hero_profile_id);
    }

    public function test_age_and_hero_profile_id_correctness(): void
    {
        $this->fakeUpstream($this->fixtureRecords());

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $young = Card::where('source_id', 'fixture-002')->firstOrFail();
        $adult = Card::where('source_id', 'fixture-003')->firstOrFail();
        $nonHero = Card::where('source_id', 'fixture-001')->firstOrFail();

        $this->assertSame('young', $young->age);
        $this->assertSame('adult', $adult->age);

        $this->assertNull($nonHero->age);
        $this->assertNull($nonHero->hero_profile_id);
    }

    public function test_idempotent_rerun_writes_nothing_new(): void
    {
        $records = $this->fixtureRecords();
        $state = $this->fakeUpstreamMutable($records);

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $tableCounts = $this->currentTableCounts();
        $updatedAtBefore = Card::where('source_id', 'fixture-001')->firstOrFail()->updated_at;

        $state->payload = $records;
        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $this->assertSame($tableCounts, $this->currentTableCounts());

        $updatedAtAfter = Card::where('source_id', 'fixture-001')->firstOrFail()->updated_at;
        $this->assertEquals($updatedAtBefore, $updatedAtAfter);
    }

    public function test_upsert_on_changed_record_updates_without_creating_new_hero(): void
    {
        $records = $this->fixtureRecords();
        $state = $this->fakeUpstreamMutable($records);

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $cardsCountBefore = Card::count();
        $heroesCountBefore = Hero::count();
        $heroProfilesCountBefore = HeroProfile::count();
        $hashBefore = Card::where('source_id', 'fixture-001')->firstOrFail()->source_hash;

        sleep(1);

        $changedRecords = $records;
        $changedRecords[0]['name'] = 'Veiled Aegis, Reforged';
        $changedRecords[0]['functional_text_plain'] = 'Play this card only during your action phase.\nWard 10 (updated)';

        $state->payload = $changedRecords;
        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $this->assertSame($cardsCountBefore, Card::count());
        $this->assertSame($heroesCountBefore, Hero::count());
        $this->assertSame($heroProfilesCountBefore, HeroProfile::count());

        $card = Card::where('source_id', 'fixture-001')->firstOrFail();
        $this->assertSame('Veiled Aegis, Reforged', $card->name);
        $this->assertNotSame($hashBefore, $card->source_hash);
    }

    public function test_printings_are_ignored_without_error(): void
    {
        $this->fakeUpstream($this->fixtureRecords());

        $this->artisan('data:ingest-cards')->assertExitCode(0);

        $this->assertDatabaseCount('cards', 7);
    }

    public function test_non_2xx_upstream_response_aborts_cleanly(): void
    {
        Http::fake([
            '*' => Http::response('', 500),
        ]);

        $exitCode = Artisan::call('data:ingest-cards');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertDatabaseCount('cards', 0);
    }

    public function test_malformed_empty_payload_aborts_cleanly(): void
    {
        $this->fakeUpstream([]);

        $exitCode = Artisan::call('data:ingest-cards');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertDatabaseCount('cards', 0);
    }

    public function test_malformed_non_array_payload_aborts_cleanly(): void
    {
        Http::fake([
            '*' => Http::response('"just a string"', 200),
        ]);

        $exitCode = Artisan::call('data:ingest-cards');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertDatabaseCount('cards', 0);
    }

    /**
     * @return array<string, int>
     */
    private function currentTableCounts(): array
    {
        return [
            'cards' => Card::count(),
            'card_types' => CardType::count(),
            'card_classes' => DB::table('card_classes')->count(),
            'card_talents' => DB::table('card_talents')->count(),
            'card_keywords' => DB::table('card_keywords')->count(),
            'card_legality' => CardLegality::count(),
            'heroes' => Hero::count(),
            'hero_profiles' => HeroProfile::count(),
        ];
    }
}
