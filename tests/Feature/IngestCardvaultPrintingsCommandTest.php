<?php

namespace Tests\Feature;

use App\Console\Commands\IngestCardvaultPrintings;
use App\Models\Card;
use App\Models\CardPrinting;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class IngestCardvaultPrintingsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function fakeWebpBytes(): string
    {
        $image = imagecreatetruecolor(60, 80);
        ob_start();
        imagewebp($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * Generates real GD-decodable image bytes wider than CardImageMirror::MAX_IMAGE_WIDTH,
     * so tests can assert the resize path actually runs (rather than being vacuously
     * satisfied by an already-narrow source image).
     */
    private function fakeWideWebpBytes(): string
    {
        $image = imagecreatetruecolor(960, 1280);
        ob_start();
        imagewebp($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function searchFixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/cardvault-search.json')), true);
    }

    private function detailFixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/cardvault-card-detail.json')), true);
    }

    /**
     * Fakes the standard upstream: search, detail, and image endpoints. More
     * specific URL patterns are registered before broad ones so they win,
     * since Http::fake resolves patterns in registration order.
     */
    private function fakeStandardUpstream(): void
    {
        Http::fake([
            '*/advanced-search/*' => Http::response($this->searchFixture()),
            '*/card_id/*' => Http::response($this->detailFixture()),
            '*legendstory-production*' => Http::response($this->fakeWebpBytes()),
        ]);
    }

    /**
     * Same as fakeStandardUpstream, but the detail endpoint's response body
     * can be swapped mid-test by mutating the returned holder's `payload`
     * property. A second call to Http::fake for the same pattern would not
     * take effect, since Laravel resolves fakes in registration order and
     * the first matching stub always wins.
     */
    private function fakeStandardUpstreamWithMutableDetail(): \stdClass
    {
        $state = new \stdClass;
        $state->payload = $this->detailFixture();

        Http::fake([
            '*/advanced-search/*' => Http::response($this->searchFixture()),
            '*/card_id/*' => fn () => Http::response($state->payload),
            '*legendstory-production*' => Http::response($this->fakeWebpBytes()),
        ]);

        return $state;
    }

    /**
     * Resolves and runs a brand new command instance from the container, the
     * same way each separate `php artisan` invocation would in production.
     * Laravel's console Application reuses a single registered Command
     * instance across repeated `$this->artisan()` calls within one test, so
     * the command's per-run instance caches would otherwise leak between
     * what are meant to be two independent runs.
     */
    private function runIngestPrintingsFresh(): int
    {
        $command = app(IngestCardvaultPrintings::class);
        $command->setLaravel(app());

        return $command->run(new ArrayInput([]), new NullOutput);
    }

    public function test_creates_printings_with_correct_field_mapping(): void
    {
        $this->fakeStandardUpstream();
        $card = Card::factory()->create(['name' => 'Chorus of Ironsong']);

        $this->artisan('data:ingest-printings')->assertExitCode(0);

        $printing = CardPrinting::where('cardvault_print_id', 'DTD208')->first();

        $this->assertNotNull($printing);
        $this->assertSame('DTD', $printing->set_code);
        $this->assertSame('majestic', $printing->rarity);
        $this->assertSame('regular', $printing->finish);
        $this->assertSame($card->id, $printing->card_id);
    }

    public function test_multiple_prints_for_one_card_all_link_to_the_same_card(): void
    {
        $this->fakeStandardUpstream();
        $card = Card::factory()->create(['name' => 'Chorus of Ironsong']);

        $this->artisan('data:ingest-printings')->assertExitCode(0);

        $this->assertSame(2, CardPrinting::count());

        $regular = CardPrinting::where('cardvault_print_id', 'DTD208')->first();
        $foil = CardPrinting::where('cardvault_print_id', 'DTD208-RF')->first();

        $this->assertNotNull($regular);
        $this->assertNotNull($foil);
        $this->assertSame($card->id, $regular->card_id);
        $this->assertSame($card->id, $foil->card_id);
        $this->assertSame(2, $card->printings()->count());
    }

    public function test_regular_art_type_maps_to_null_art_variant_and_others_pass_through(): void
    {
        $this->fakeStandardUpstream();
        Card::factory()->create(['name' => 'Chorus of Ironsong']);

        $this->artisan('data:ingest-printings')->assertExitCode(0);

        $regular = CardPrinting::where('cardvault_print_id', 'DTD208')->firstOrFail();
        $foil = CardPrinting::where('cardvault_print_id', 'DTD208-RF')->firstOrFail();

        $this->assertNull($regular->art_variant);
        $this->assertSame('alternate', $foil->art_variant);
    }

    public function test_image_is_mirrored_to_spaces_and_image_url_points_at_spaces(): void
    {
        Storage::fake('spaces');
        $this->fakeStandardUpstream();
        Card::factory()->create(['name' => 'Chorus of Ironsong']);

        $this->artisan('data:ingest-printings')->assertExitCode(0);

        $this->assertTrue(Storage::disk('spaces')->exists('card-printings/DTD208.webp'));

        $bytes = Storage::disk('spaces')->get('card-printings/DTD208.webp');
        $size = getimagesizefromstring($bytes);
        $this->assertNotFalse($size);
        $this->assertLessThanOrEqual(480, $size[0]);

        $printing = CardPrinting::where('cardvault_print_id', 'DTD208')->firstOrFail();
        $this->assertStringContainsString('card-printings/DTD208.webp', $printing->image_url);
        $this->assertStringNotContainsString('legendstory-production', $printing->image_url);
    }

    public function test_idempotent_rerun_does_not_redownload_or_remirror(): void
    {
        Storage::fake('spaces');
        $this->fakeStandardUpstream();
        Card::factory()->create(['name' => 'Chorus of Ironsong']);

        $this->assertSame(Command::SUCCESS, $this->runIngestPrintingsFresh());

        $countAfterFirst = CardPrinting::count();
        $imageRequestsAfterFirst = count(Http::recorded(fn ($request) => str_contains((string) $request->url(), 'legendstory-production')));

        $printing = CardPrinting::where('cardvault_print_id', 'DTD208')->firstOrFail();
        $imageUrlBefore = $printing->image_url;
        $hashBefore = $printing->image_source_hash;

        $this->assertSame(Command::SUCCESS, $this->runIngestPrintingsFresh());

        $this->assertSame($countAfterFirst, CardPrinting::count());

        $imageRequestsAfterSecond = count(Http::recorded(fn ($request) => str_contains((string) $request->url(), 'legendstory-production')));
        $this->assertSame($imageRequestsAfterFirst, $imageRequestsAfterSecond);

        $printing->refresh();
        $this->assertSame($imageUrlBefore, $printing->image_url);
        $this->assertSame($hashBefore, $printing->image_source_hash);
    }

    public function test_changed_source_image_url_remirrors_and_updates_hash_and_url(): void
    {
        Storage::fake('spaces');
        $state = $this->fakeStandardUpstreamWithMutableDetail();
        Card::factory()->create(['name' => 'Chorus of Ironsong']);

        $this->assertSame(Command::SUCCESS, $this->runIngestPrintingsFresh());

        $printing = CardPrinting::where('cardvault_print_id', 'DTD208')->firstOrFail();
        $urlBefore = $printing->image_url;
        $hashBefore = $printing->image_source_hash;

        $state->payload['results'][0]['card_prints'][0]['faces'][0]['image']['normal'] .= '?v=2';

        $this->assertSame(Command::SUCCESS, $this->runIngestPrintingsFresh());

        $printing->refresh();
        $this->assertNotSame($hashBefore, $printing->image_source_hash);
        $this->assertSame($urlBefore, $printing->image_url);
        $this->assertStringNotContainsString('legendstory-production', $printing->image_url);
    }

    public function test_card_with_no_cardvault_match_is_flagged_and_run_succeeds(): void
    {
        Log::spy();
        Http::fake([
            '*/advanced-search/*' => Http::response(['count' => 0, 'next' => null, 'previous' => null, 'results' => []]),
        ]);
        Card::factory()->create(['name' => 'Totally Unknown Card Xyz']);

        $this->artisan('data:ingest-printings')->assertExitCode(0);

        Log::shouldHaveReceived('warning')->with('No cardvault match for card', Mockery::any())->atLeast()->once();
        $this->assertSame(0, CardPrinting::count());
    }

    public function test_partial_name_matches_are_rejected(): void
    {
        $this->fakeStandardUpstream();
        Card::factory()->create(['name' => 'Chorus of Ironsong']);

        $this->artisan('data:ingest-printings')->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'chorus-of-ironsong-redux-9'));
    }

    public function test_failed_image_download_is_flagged_and_row_still_persisted(): void
    {
        Log::spy();
        Http::fake([
            '*/advanced-search/*' => Http::response($this->searchFixture()),
            '*/card_id/*' => Http::response($this->detailFixture()),
            '*legendstory-production*' => Http::response('', 500),
        ]);
        Card::factory()->create(['name' => 'Chorus of Ironsong']);

        $this->artisan('data:ingest-printings')->assertExitCode(0);

        $printing = CardPrinting::where('cardvault_print_id', 'DTD208')->first();

        $this->assertNotNull($printing);
        $this->assertSame('DTD', $printing->set_code);
        $this->assertSame('majestic', $printing->rarity);
        $this->assertSame('regular', $printing->finish);
        $this->assertNull($printing->image_url);

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_search_pagination_is_followed(): void
    {
        $page1 = [
            'count' => 1,
            'next' => 'https://api.cardvault.fabtcg.com/carddb/api/v1/advanced-search/?page=2',
            'previous' => null,
            'results' => [
                [
                    'card_id' => 'something-else-1',
                    'printed_name' => 'Something Else',
                    'faces' => [['image' => ['normal' => 'https://legendstory-production-s3-public.s3.amazonaws.com/media/cards/normal/OTHER.webp']]],
                ],
            ],
        ];

        $page2 = [
            'count' => 1,
            'next' => null,
            'previous' => null,
            'results' => [
                [
                    'card_id' => 'page-two-card-1',
                    'printed_name' => 'Page Two Card',
                    'faces' => [['image' => ['normal' => 'https://legendstory-production-s3-public.s3.amazonaws.com/media/cards/normal/PGE001.webp']]],
                ],
            ],
        ];

        $detail = [
            'count' => 1,
            'next' => null,
            'previous' => null,
            'results' => [
                [
                    'id' => 'fake-id',
                    'card_id' => 'page-two-card-1',
                    'card_prints' => [
                        [
                            'id' => 'fake-print-id',
                            'print_id' => 'PGE001',
                            'rarity' => 'common',
                            'print_set' => ['set_code' => 'PGE', 'set_name' => 'Page Set', 'set_type' => 'expansion'],
                            'faces' => [
                                [
                                    'finish_type' => 'regular',
                                    'art_type' => 'regular',
                                    'image' => ['normal' => 'https://legendstory-production-s3-public.s3.amazonaws.com/media/cards/normal/PGE001.webp'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            '*/advanced-search/*page=2*' => Http::response($page2),
            '*/advanced-search/*' => Http::response($page1),
            '*/card_id/page-two-card-1/*' => Http::response($detail),
            '*legendstory-production*' => Http::response($this->fakeWebpBytes()),
        ]);

        $card = Card::factory()->create(['name' => 'Page Two Card']);

        $this->artisan('data:ingest-printings')->assertExitCode(0);

        $printing = CardPrinting::where('cardvault_print_id', 'PGE001')->first();
        $this->assertNotNull($printing);
        $this->assertSame($card->id, $printing->card_id);
    }

    public function test_run_with_no_cards_fails_cleanly(): void
    {
        $exitCode = $this->artisan('data:ingest-printings')->run();

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertSame(0, CardPrinting::count());
    }

    public function test_wide_source_image_is_actually_downscaled(): void
    {
        Storage::fake('spaces');
        Http::fake([
            '*/advanced-search/*' => Http::response($this->searchFixture()),
            '*/card_id/*' => Http::response($this->detailFixture()),
            '*legendstory-production*' => Http::response($this->fakeWideWebpBytes()),
        ]);
        Card::factory()->create(['name' => 'Chorus of Ironsong']);

        $this->artisan('data:ingest-printings')->assertExitCode(0);

        $bytes = Storage::disk('spaces')->get('card-printings/DTD208.webp');
        $size = getimagesizefromstring($bytes);

        $this->assertNotFalse($size);
        $this->assertSame(480, $size[0]);
    }

    public function test_undecodable_image_bytes_are_flagged_and_row_still_persisted(): void
    {
        Log::spy();
        Http::fake([
            '*/advanced-search/*' => Http::response($this->searchFixture()),
            '*/card_id/*' => Http::response($this->detailFixture()),
            '*legendstory-production*' => Http::response('not a real image'),
        ]);
        Card::factory()->create(['name' => 'Chorus of Ironsong']);

        $this->artisan('data:ingest-printings')->assertExitCode(0);

        $printing = CardPrinting::where('cardvault_print_id', 'DTD208')->first();

        $this->assertNotNull($printing);
        $this->assertSame('DTD', $printing->set_code);
        $this->assertNull($printing->image_url);

        Log::shouldHaveReceived('warning')->with('Failed to mirror card image', Mockery::any())->atLeast()->once();
    }

    public function test_existing_image_is_preserved_when_a_remirror_attempt_fails(): void
    {
        Storage::fake('spaces');

        $state = new \stdClass;
        $state->payload = $this->detailFixture();
        $state->imageShouldFail = false;

        Http::fake([
            '*/advanced-search/*' => Http::response($this->searchFixture()),
            '*/card_id/*' => fn () => Http::response($state->payload),
            '*legendstory-production*' => fn () => $state->imageShouldFail
                ? Http::response('', 500)
                : Http::response($this->fakeWebpBytes()),
        ]);

        Card::factory()->create(['name' => 'Chorus of Ironsong']);

        $this->assertSame(Command::SUCCESS, $this->runIngestPrintingsFresh());

        $printing = CardPrinting::where('cardvault_print_id', 'DTD208')->firstOrFail();
        $urlBefore = $printing->image_url;
        $hashBefore = $printing->image_source_hash;

        $state->payload['results'][0]['card_prints'][0]['faces'][0]['image']['normal'] .= '?v=2';
        $state->imageShouldFail = true;

        $this->assertSame(Command::SUCCESS, $this->runIngestPrintingsFresh());

        $printing->refresh();
        $this->assertSame($urlBefore, $printing->image_url);
        $this->assertSame($hashBefore, $printing->image_source_hash);
    }
}
