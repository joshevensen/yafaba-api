<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\CardType;
use App\Models\StapleStat;
use Carbon\CarbonInterface;
use Dom\HTMLDocument;
use Dom\XPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('data:ingest-staples {--base-url= : Override the FABREC base URL}')]
#[Description('Scrape fabrec.gg per-hero card inclusion rates into staple_stats, resolving heroes by card_type and cards by name.')]
class IngestStapleStats extends Command
{
    public const BASE_URL = 'https://fabrec.gg';

    public const SOURCE = 'fabrec.gg';

    public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const XHTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    private int $created = 0;

    private int $updated = 0;

    private int $flagged = 0;

    /**
     * Parsed card rows for the run, keyed by hero slug, so a slug shared by
     * multiple hero cards is fetched and parsed only once.
     *
     * @var array<string, array<int, array{name: string, rate: float}>>
     */
    private array $pageCache = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $heroTypeId = CardType::where('name', 'hero')->value('id');

        if ($heroTypeId === null) {
            $this->error('No hero card type found; run the FormatSeeder/card-type seeding first.');

            return Command::FAILURE;
        }

        $heroCards = Card::where('card_type_id', $heroTypeId)->orderBy('name')->get();

        if ($heroCards->isEmpty()) {
            $this->error('No hero cards found; run data:ingest-cards first.');

            return Command::FAILURE;
        }

        $baseUrl = $this->resolveBaseUrl();

        $this->withProgressBar($heroCards, function (Card $heroCard) use ($baseUrl): void {
            $this->ingestHero($heroCard, $baseUrl);
        });

        $this->newLine(2);
        $this->info("Staple stats ingest complete: {$this->created} created, {$this->updated} updated, {$this->flagged} flagged.");

        return Command::SUCCESS;
    }

    /**
     * Resolve the FABREC base URL for this run.
     */
    private function resolveBaseUrl(): string
    {
        $url = $this->option('base-url');

        return is_string($url) && $url !== '' ? $url : self::BASE_URL;
    }

    /**
     * Fetch (or reuse from the run's page cache) and persist inclusion-rate
     * rows for a single hero card.
     */
    private function ingestHero(Card $heroCard, string $baseUrl): void
    {
        $slug = $this->slugify($heroCard->name);
        $fetchedAt = now();

        if (! array_key_exists($slug, $this->pageCache)) {
            $url = "{$baseUrl}/hero/{$slug}";

            try {
                $html = Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(30)->get($url)->throw()->body();
            } catch (Throwable $e) {
                Log::warning('Failed to fetch fabrec hero page', [
                    'hero_card_id' => $heroCard->id,
                    'name' => $heroCard->name,
                    'url' => $url,
                    'message' => $e->getMessage(),
                ]);
                $this->warn("Failed to fetch fabrec hero page for {$heroCard->name}: {$e->getMessage()}");
                $this->flagged++;

                return;
            }

            $this->pageCache[$slug] = $this->parseCardRows($html);
        }

        foreach ($this->pageCache[$slug] as $row) {
            $this->resolveAndPersist($heroCard, $row, $fetchedAt);
        }
    }

    /**
     * Slugify a hero card's name into fabrec's URL slug format: lowercase,
     * every run of non-alphanumeric characters collapsed to one hyphen, with
     * leading/trailing hyphens trimmed.
     */
    private function slugify(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');
    }

    /**
     * Build an XPath evaluator for the given HTML, with the XHTML namespace
     * registered under the "h" prefix (required for every element step).
     */
    private function xpathFor(string $html): XPath
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $xpath = new XPath($document);
        $xpath->registerNamespace('h', self::XHTML_NAMESPACE);

        return $xpath;
    }

    /**
     * Parse a fabrec hero page into its card-inclusion rows. Malformed rows
     * are flagged and skipped, never abort the whole page.
     *
     * @return array<int, array{name: string, rate: float}>
     */
    private function parseCardRows(string $html): array
    {
        $xpath = $this->xpathFor($html);

        $containers = $xpath->query("//h:div[contains(@class, 'card_cardButton')]");

        $rows = [];

        foreach ($containers as $container) {
            $nameNode = $xpath->query(".//h:div[contains(@class, 'card_name')]", $container)->item(0);
            $name = $nameNode === null ? '' : $this->normalizeText($nameNode->textContent);

            $rawText = trim($container->textContent);

            if ($name === '' || ! preg_match('/(\d+(?:\.\d+)?)\s*%\s*of\s+decks/i', $rawText, $m)) {
                Log::warning('Skipping malformed fabrec card row', ['raw_text' => $rawText]);
                $this->warn("Skipping malformed fabrec card row: {$rawText}");
                $this->flagged++;

                continue;
            }

            $rows[] = [
                'name' => $name,
                'rate' => round((float) $m[1] / 100, 4),
            ];
        }

        return $rows;
    }

    /**
     * Normalize scraped text: replace non-breaking spaces, collapse
     * whitespace runs to single spaces, and trim.
     */
    private function normalizeText(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Resolve a parsed row's card name to every matching Card row and persist
     * a staple_stats row for each. Unmatched names are flagged and skipped.
     *
     * @param  array{name: string, rate: float}  $row
     */
    private function resolveAndPersist(Card $heroCard, array $row, CarbonInterface $fetchedAt): void
    {
        $cardIds = Card::whereRaw('LOWER(name) = ?', [mb_strtolower($row['name'])])->pluck('id')->all();

        if ($cardIds === []) {
            Log::warning('Unmatched fabrec card name', [
                'hero_card_id' => $heroCard->id,
                'name' => $row['name'],
            ]);
            $this->warn("Unmatched fabrec card name: {$row['name']}");
            $this->flagged++;

            return;
        }

        foreach ($cardIds as $cardId) {
            $this->persistStapleStat($heroCard->id, $cardId, $row['rate'], $fetchedAt);
        }
    }

    /**
     * Write (or overwrite) the staple_stats row for one (hero, card, source)
     * observation, tallying created vs. updated.
     */
    private function persistStapleStat(string $heroCardId, string $cardId, float $rate, CarbonInterface $fetchedAt): void
    {
        DB::transaction(function () use ($heroCardId, $cardId, $rate, $fetchedAt): void {
            $existing = StapleStat::where('hero_id', $heroCardId)
                ->where('card_id', $cardId)
                ->where('source', self::SOURCE)
                ->first();

            StapleStat::updateOrCreate(
                ['hero_id' => $heroCardId, 'card_id' => $cardId, 'source' => self::SOURCE],
                ['inclusion_rate' => $rate, 'fetched_at' => $fetchedAt],
            );

            if ($existing === null) {
                $this->created++;
            } else {
                $this->updated++;
            }
        });
    }
}
