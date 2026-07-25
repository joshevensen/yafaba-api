<?php

namespace Tests\Feature;

use App\Models\CardPrinting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class IngestTcgcsvPricingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function groupsCsv(): string
    {
        return file_get_contents(base_path('tests/Fixtures/tcgcsv-groups.csv'));
    }

    private function productsAndPricesCsv(): string
    {
        return file_get_contents(base_path('tests/Fixtures/tcgcsv-products-and-prices.csv'));
    }

    private function noPriceColumnsCsv(): string
    {
        return file_get_contents(base_path('tests/Fixtures/tcgcsv-products-and-prices-no-price-columns.csv'));
    }

    /**
     * Fakes the standard three-group upstream: group 2775 with real pricing data,
     * group 24773 with no price columns, and group 9999 with no fixture (404).
     * More specific URL patterns are registered before the broad group-CSV pattern
     * so they win, since Http::fake resolves in registration order.
     */
    private function fakeStandardUpstream(): void
    {
        Http::fake([
            '*/tcgplayer/62/Groups.csv' => Http::response($this->groupsCsv()),
            '*/tcgplayer/62/2775/ProductsAndPrices.csv' => Http::response($this->productsAndPricesCsv()),
            '*/tcgplayer/62/24773/ProductsAndPrices.csv' => Http::response($this->noPriceColumnsCsv()),
            '*/tcgplayer/62/9999/ProductsAndPrices.csv' => Http::response('', 404),
        ]);
    }

    public function test_matched_printing_price_cache_and_timestamp_are_updated(): void
    {
        $this->fakeStandardUpstream();

        $printing = CardPrinting::factory()->create(['cardvault_print_id' => 'MON001']);

        $this->artisan('data:ingest-pricing')->assertExitCode(0);

        $printing->refresh();
        $this->assertEquals(40.25, (float) $printing->price_cache);
        $this->assertNotNull($printing->price_updated_at);
    }

    public function test_all_printings_sharing_a_print_id_are_updated(): void
    {
        $this->fakeStandardUpstream();

        $first = CardPrinting::factory()->create(['cardvault_print_id' => 'MON001']);
        $second = CardPrinting::factory()->create(['cardvault_print_id' => 'MON001']);

        $this->artisan('data:ingest-pricing')->assertExitCode(0);

        $first->refresh();
        $second->refresh();
        $this->assertEquals(40.25, (float) $first->price_cache);
        $this->assertEquals(40.25, (float) $second->price_cache);
    }

    public function test_normal_subtype_row_wins_over_foil_row_for_same_ext_number(): void
    {
        $this->fakeStandardUpstream();

        $printing = CardPrinting::factory()->create(['cardvault_print_id' => 'MON001']);

        $this->artisan('data:ingest-pricing')->assertExitCode(0);

        $printing->refresh();
        $this->assertEquals(40.25, (float) $printing->price_cache);
        $this->assertNotEquals(999.99, (float) $printing->price_cache);
    }

    public function test_exact_normal_subtype_wins_over_edition_prefixed_normal_for_same_ext_number(): void
    {
        $this->fakeStandardUpstream();

        $printing = CardPrinting::factory()->create(['cardvault_print_id' => 'MON005']);

        $this->artisan('data:ingest-pricing')->assertExitCode(0);

        $printing->refresh();
        $this->assertEquals(6.50, (float) $printing->price_cache);
        $this->assertNotEquals(8.00, (float) $printing->price_cache);
    }

    public function test_unmatched_ext_number_does_not_fail_the_command(): void
    {
        $this->fakeStandardUpstream();

        $this->assertFalse(CardPrinting::where('cardvault_print_id', 'MON404')->exists());

        $this->artisan('data:ingest-pricing')->assertExitCode(0);
    }

    public function test_sealed_product_row_with_empty_ext_number_is_skipped(): void
    {
        $this->fakeStandardUpstream();

        $emptyString = CardPrinting::factory()->create(['cardvault_print_id' => '']);
        $null = CardPrinting::factory()->create(['cardvault_print_id' => null]);

        $this->artisan('data:ingest-pricing')->assertExitCode(0);

        $emptyString->refresh();
        $null->refresh();
        $this->assertNull($emptyString->price_cache);
        $this->assertNull($null->price_cache);
    }

    public function test_empty_market_price_row_does_not_overwrite_existing_price(): void
    {
        $this->fakeStandardUpstream();

        $fixedTimestamp = Carbon::parse('2020-01-01 00:00:00');
        $printing = CardPrinting::factory()->create([
            'cardvault_print_id' => 'MON003',
            'price_cache' => 7.77,
            'price_updated_at' => $fixedTimestamp,
        ]);

        $this->artisan('data:ingest-pricing')->assertExitCode(0);

        $printing->refresh();
        $this->assertEquals(7.77, (float) $printing->price_cache);
        $this->assertTrue($fixedTimestamp->equalTo($printing->price_updated_at));
    }

    public function test_group_csv_without_price_columns_is_handled(): void
    {
        $this->fakeStandardUpstream();

        $printing = CardPrinting::factory()->create(['cardvault_print_id' => 'SPW001']);

        $this->artisan('data:ingest-pricing')->assertExitCode(0);

        $printing->refresh();
        $this->assertNull($printing->price_cache);
    }

    public function test_rows_with_quoted_commas_in_description_still_match_and_price(): void
    {
        $this->fakeStandardUpstream();

        $printing = CardPrinting::factory()->create(['cardvault_print_id' => 'MON002']);

        $this->artisan('data:ingest-pricing')->assertExitCode(0);

        $printing->refresh();
        $this->assertEquals(5.30, (float) $printing->price_cache);
    }

    public function test_failing_group_is_warned_and_skipped_without_aborting(): void
    {
        Log::spy();

        $this->fakeStandardUpstream();

        $printing = CardPrinting::factory()->create(['cardvault_print_id' => 'MON001']);

        $this->artisan('data:ingest-pricing')->assertExitCode(0);

        Log::shouldHaveReceived('warning')->atLeast()->once();

        $printing->refresh();
        $this->assertEquals(40.25, (float) $printing->price_cache);
    }

    public function test_groups_csv_fetch_failure_returns_failure(): void
    {
        Http::fake([
            '*/tcgplayer/62/Groups.csv' => Http::response('', 500),
        ]);

        $printing = CardPrinting::factory()->create(['cardvault_print_id' => 'MON001']);

        $exitCode = Artisan::call('data:ingest-pricing');

        $this->assertNotSame(Command::SUCCESS, $exitCode);

        $printing->refresh();
        $this->assertNull($printing->price_cache);
    }

    public function test_empty_groups_csv_returns_failure(): void
    {
        Http::fake([
            '*/tcgplayer/62/Groups.csv' => Http::response("groupId,name,abbreviation,isSupplemental,publishedOn,modifiedOn,categoryId\n"),
        ]);

        $exitCode = Artisan::call('data:ingest-pricing');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
    }

    public function test_rerun_keeps_price_and_refreshes_timestamp(): void
    {
        $this->fakeStandardUpstream();

        $printing = CardPrinting::factory()->create(['cardvault_print_id' => 'MON001']);

        Carbon::setTestNow(now());
        $this->artisan('data:ingest-pricing')->assertExitCode(0);
        $printing->refresh();
        $firstPrice = (float) $printing->price_cache;
        $firstTimestamp = $printing->price_updated_at;

        Carbon::setTestNow(now()->addMinute());
        $this->artisan('data:ingest-pricing')->assertExitCode(0);
        $printing->refresh();
        $secondPrice = (float) $printing->price_cache;
        $secondTimestamp = $printing->price_updated_at;

        Carbon::setTestNow();

        $this->assertEquals(40.25, $firstPrice);
        $this->assertEquals(40.25, $secondPrice);
        $this->assertTrue($secondTimestamp->greaterThan($firstTimestamp));
    }

    public function test_category_option_changes_requested_urls(): void
    {
        Http::fake([
            '*' => Http::response($this->groupsCsv()),
        ]);

        Artisan::call('data:ingest-pricing', ['--category' => 99]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/tcgplayer/99/'));
    }
}
