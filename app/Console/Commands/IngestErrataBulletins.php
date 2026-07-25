<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\ErrataBulletin;
use Carbon\Carbon;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('data:ingest-errata {--index-url= : Override the errata bulletins index URL}')]
#[Description('Scrape fabtcg.com errata bulletins into errata_bulletins, resolving affected cards by name.')]
class IngestErrataBulletins extends Command
{
    public const INDEX_URL = 'https://fabtcg.com/rules-and-policy-center/errata-bulletins/';

    public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const XHTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    private int $created = 0;

    private int $skipped = 0;

    private int $flagged = 0;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $indexUrl = $this->resolveIndexUrl();

        try {
            $html = $this->fetchHtml($indexUrl);
            $entries = $this->parseIndex($html);
        } catch (Throwable $e) {
            $this->error("Failed to fetch or parse errata bulletins index: {$e->getMessage()}");

            return Command::FAILURE;
        }

        if ($entries === []) {
            $this->error('Errata bulletins index yielded zero valid entries.');

            return Command::FAILURE;
        }

        $cachedNumbers = ErrataBulletin::pluck('bulletin_number')->all();
        $cachedNumbers = array_flip($cachedNumbers);

        $newEntries = [];
        foreach ($entries as $entry) {
            if (isset($cachedNumbers[$entry['bulletin_number']])) {
                $this->skipped++;

                continue;
            }

            $newEntries[] = $entry;
        }

        $this->withProgressBar($newEntries, function (array $entry): void {
            $this->ingestBulletin($entry);
        });

        $this->newLine(2);
        $this->info("Errata ingest complete: {$this->created} created, {$this->skipped} skipped, {$this->flagged} flagged.");

        return Command::SUCCESS;
    }

    /**
     * Resolve the errata bulletins index URL for this run.
     */
    private function resolveIndexUrl(): string
    {
        $url = $this->option('index-url');

        return is_string($url) && $url !== '' ? $url : self::INDEX_URL;
    }

    /**
     * Fetch a URL's HTML body, sending the browser User-Agent required by fabtcg.com.
     */
    private function fetchHtml(string $url): string
    {
        return Http::withHeaders(['User-Agent' => self::USER_AGENT])->timeout(30)->get($url)->throw()->body();
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
     * Parse the index page HTML into a deduplicated list of bulletin entries.
     *
     * @return array<int, array{bulletin_number: string, url: string}>
     */
    private function parseIndex(string $html): array
    {
        $xpath = $this->xpathFor($html);

        $anchors = $xpath->query("//h:a[contains(@class, 'fl-link-card-ssr')]");

        $entries = [];
        $seen = [];

        foreach ($anchors as $anchor) {
            $heading = $xpath->query('.//h:h3', $anchor)->item(0);
            $href = $anchor->getAttribute('href');

            if ($heading === null || $href === null || $href === '') {
                Log::warning('Skipping malformed errata index entry', [
                    'has_heading' => $heading !== null,
                    'href' => $href,
                ]);
                $this->warn('Skipping malformed errata index entry (missing heading or href)');
                $this->flagged++;

                continue;
            }

            $headingText = trim($heading->textContent);

            if (! preg_match('/#\s*(\d+)/', $headingText, $m)) {
                Log::warning('Skipping malformed errata index entry', [
                    'heading_text' => $headingText,
                    'href' => $href,
                ]);
                $this->warn("Skipping malformed errata index entry (unparsable heading: {$headingText})");
                $this->flagged++;

                continue;
            }

            $bulletinNumber = $m[1];

            if (isset($seen[$bulletinNumber])) {
                continue;
            }

            $seen[$bulletinNumber] = true;
            $entries[] = [
                'bulletin_number' => $bulletinNumber,
                'url' => $href,
            ];
        }

        return $entries;
    }

    /**
     * Fetch and ingest a single new bulletin, creating no row on any parse failure.
     *
     * @param  array{bulletin_number: string, url: string}  $entry
     */
    private function ingestBulletin(array $entry): void
    {
        try {
            $html = $this->fetchHtml($entry['url']);
        } catch (Throwable $e) {
            Log::warning('Failed to parse errata bulletin article', [
                'bulletin_number' => $entry['bulletin_number'],
                'url' => $entry['url'],
            ]);
            $this->warn("Failed to fetch errata bulletin article {$entry['bulletin_number']}: {$e->getMessage()}");
            $this->flagged++;

            return;
        }

        $article = $this->parseArticle($html);

        if ($article === null) {
            Log::warning('Failed to parse errata bulletin article', [
                'bulletin_number' => $entry['bulletin_number'],
                'url' => $entry['url'],
            ]);
            $this->warn("Failed to parse errata bulletin article {$entry['bulletin_number']}");
            $this->flagged++;

            return;
        }

        ErrataBulletin::create([
            'bulletin_number' => $entry['bulletin_number'],
            'url' => $entry['url'],
            'published_date' => $article['published_date'],
            'content' => $article['content'],
            'affected_card_ids' => $article['affected_card_ids'],
            'cached_at' => now(),
        ]);

        $this->created++;
    }

    /**
     * Parse a bulletin article page's HTML into its persisted fields, or null
     * if the required published-date meta or entry-content div is missing.
     *
     * @return null|array{published_date: string, content: string, affected_card_ids: array<int, string>}
     */
    private function parseArticle(string $html): ?array
    {
        $xpath = $this->xpathFor($html);

        $publishedMeta = $xpath->query("//h:meta[@property='article:published_time']")->item(0);

        if ($publishedMeta === null) {
            return null;
        }

        $publishedContent = $publishedMeta->getAttribute('content');

        if ($publishedContent === null || $publishedContent === '') {
            return null;
        }

        try {
            $publishedDate = Carbon::parse($publishedContent)->utc()->toDateString();
        } catch (Throwable) {
            return null;
        }

        $contentDiv = $xpath->query("//h:div[contains(concat(' ', normalize-space(@class), ' '), ' entry-content ')]")->item(0);

        if ($contentDiv === null) {
            return null;
        }

        $content = trim($this->innerHtml($contentDiv));
        $affectedCardIds = $this->resolveAffectedCardIds($xpath, $contentDiv);

        return [
            'published_date' => $publishedDate,
            'content' => $content,
            'affected_card_ids' => $affectedCardIds,
        ];
    }

    /**
     * Concatenate the serialized HTML of every child node of the given element,
     * excluding the element's own opening and closing tags.
     */
    private function innerHtml(Element $element): string
    {
        $document = $element->ownerDocument;
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }

    /**
     * Resolve the sorted, deduplicated list of card ids affected by a bulletin,
     * by matching every heading inside the content div against card names.
     *
     * @return array<int, string>
     */
    private function resolveAffectedCardIds(XPath $xpath, Element $contentDiv): array
    {
        $headings = $xpath->query('.//h:h2 | .//h:h3 | .//h:h4 | .//h:h5 | .//h:h6', $contentDiv);

        $allIds = [];

        foreach ($headings as $heading) {
            $text = str_replace("\u{00A0}", ' ', $heading->textContent);
            $text = trim(preg_replace('/\s+/', ' ', $text));

            if ($text === '') {
                continue;
            }

            $ids = Card::whereRaw('LOWER(name) = ?', [mb_strtolower($text)])->pluck('id')->all();

            foreach ($ids as $id) {
                $allIds[] = $id;
            }
        }

        $allIds = array_values(array_unique($allIds));
        sort($allIds);

        return $allIds;
    }
}
