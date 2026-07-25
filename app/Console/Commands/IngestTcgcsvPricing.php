<?php

namespace App\Console\Commands;

use App\Models\CardPrinting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('data:ingest-pricing {--category=62 : tcgcsv.com categoryId to ingest (default 62 = Flesh & Blood TCG)}')]
#[Description('Refresh card_printings market pricing from tcgcsv.com.')]
class IngestTcgcsvPricing extends Command
{
    public const BASE_URL = 'https://tcgcsv.com/tcgplayer';

    private int $groupsProcessed = 0;

    private int $groupsFailed = 0;

    private int $printingsUpdated = 0;

    private int $skippedSealed = 0;

    private int $skippedNoPrice = 0;

    private int $unmatched = 0;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $category = (string) $this->option('category');

        try {
            $groups = $this->fetchGroups($category);
        } catch (Throwable $e) {
            $this->error("Failed to fetch tcgcsv groups: {$e->getMessage()}");

            return Command::FAILURE;
        }

        if ($groups === []) {
            $this->error('Upstream tcgcsv groups data was empty or malformed.');

            return Command::FAILURE;
        }

        foreach ($groups as $group) {
            $groupId = $group['groupId'] ?? null;

            if (! is_string($groupId) || $groupId === '') {
                continue;
            }

            $this->processGroup($category, $groupId);
        }

        $this->info(
            "Pricing refresh complete: {$this->groupsProcessed} groups processed, {$this->groupsFailed} groups failed, "
            ."{$this->printingsUpdated} printings updated, {$this->skippedSealed} skipped (no card number), "
            ."{$this->skippedNoPrice} skipped (no price), {$this->unmatched} unmatched."
        );

        return Command::SUCCESS;
    }

    /**
     * Fetch and parse the Groups.csv for the given category.
     *
     * @return array<int, array<string, string>>
     */
    private function fetchGroups(string $category): array
    {
        $response = Http::timeout(180)->get(self::BASE_URL."/{$category}/Groups.csv")->throw();

        return $this->parseCsv($response->body());
    }

    /**
     * Fetch and process a single group's ProductsAndPrices.csv, updating pricing counters.
     */
    private function processGroup(string $category, string $groupId): void
    {
        try {
            $response = Http::timeout(180)
                ->get(self::BASE_URL."/{$category}/{$groupId}/ProductsAndPrices.csv")
                ->throw();
        } catch (Throwable $e) {
            Log::warning('Failed to fetch tcgcsv group prices', [
                'groupId' => $groupId,
                'message' => $e->getMessage(),
            ]);
            $this->warn("Failed to fetch prices for group {$groupId}: {$e->getMessage()}");
            $this->groupsFailed++;

            return;
        }

        $rows = $this->parseCsv($response->body());
        $representatives = $this->selectRepresentativeRows($rows);

        foreach ($representatives as $row) {
            $affected = CardPrinting::where('cardvault_print_id', $row['extNumber'])->update([
                'price_cache' => round((float) $row['marketPrice'], 2),
                'price_updated_at' => now(),
            ]);

            if ($affected === 0) {
                $this->unmatched++;
            } else {
                $this->printingsUpdated += $affected;
            }
        }

        $this->groupsProcessed++;
    }

    /**
     * Filter raw CSV rows down to one representative row per distinct extNumber,
     * skipping sealed products (no extNumber) and rows without a usable marketPrice.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<string, array<string, string>>
     */
    private function selectRepresentativeRows(array $rows): array
    {
        /** @var array<string, array{row: array<string, string>, rank: int}> $best */
        $best = [];

        foreach ($rows as $row) {
            $extNumber = trim($row['extNumber'] ?? '');

            if ($extNumber === '') {
                $this->skippedSealed++;

                continue;
            }

            $marketPrice = $row['marketPrice'] ?? '';

            if (! is_numeric($marketPrice) || (float) $marketPrice <= 0) {
                $this->skippedNoPrice++;

                continue;
            }

            $rank = $this->subTypeRank($row['subTypeName'] ?? '');
            $row['extNumber'] = $extNumber;
            $row['marketPrice'] = $marketPrice;

            if (! isset($best[$extNumber]) || $rank < $best[$extNumber]['rank']) {
                $best[$extNumber] = ['row' => $row, 'rank' => $rank];
            }
        }

        return array_map(fn (array $entry): array => $entry['row'], $best);
    }

    /**
     * Rank a subTypeName for representative-row selection: 0 = exactly "Normal",
     * 1 = ends with "Normal" (e.g. "1st Edition Normal"), 2 = anything else.
     */
    private function subTypeRank(string $subTypeName): int
    {
        $subTypeName = trim($subTypeName);

        if ($subTypeName === 'Normal') {
            return 0;
        }

        if ($subTypeName !== '' && str_ends_with($subTypeName, 'Normal')) {
            return 1;
        }

        return 2;
    }

    /**
     * Parse a CSV body by header name using core PHP, tolerant of missing
     * columns and rows whose field count doesn't match the header.
     *
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $body): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body);
        rewind($stream);

        $header = fgetcsv($stream);

        if ($header === false) {
            fclose($stream);

            return [];
        }

        $rows = [];

        while (($data = fgetcsv($stream)) !== false) {
            if (count($data) !== count($header)) {
                continue;
            }

            $rows[] = array_combine($header, $data);
        }

        fclose($stream);

        return $rows;
    }
}
