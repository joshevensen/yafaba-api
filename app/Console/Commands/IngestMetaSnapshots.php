<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\CardType;
use App\Models\Format;
use App\Models\MetaSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

#[Signature('data:ingest-meta {--fabtcgmeta-url= : Override the fabtcgmeta hero-performance API URL} {--fablazing-url= : Override the fablazing meta base URL}')]
#[Description('Scrape hero win-rate data from fabtcgmeta.com and fablazing.com into meta_snapshots.')]
class IngestMetaSnapshots extends Command
{
    public const FABTCGMETA_URL = 'https://api.fabtcgmeta.com/api/meta/hero-performance';

    public const FABLAZING_BASE_URL = 'https://fablazing.com/meta';

    public const USER_AGENT = 'YaFaBa-Data-Ingest/1.0 (+https://github.com/joshevensen/yafaba-api)';

    public const SOURCE_FABTCGMETA = 'fabtcgmeta';

    public const SOURCE_FABLAZING = 'fablazing';

    /**
     * fabtcgmeta's own format codes, mapped to this app's canonical abbreviation.
     * Not the canonical format list itself — filtered against `formats` at runtime.
     *
     * @var array<string, string>
     */
    private const FABTCGMETA_FORMAT_CODES = [
        'CC-O' => 'CC',
        'LL-O' => 'LL',
        'Silver Age-O' => 'SAGE',
    ];

    /**
     * fablazing's own route slugs, mapped to this app's canonical abbreviation.
     *
     * @var array<string, string>
     */
    private const FABLAZING_FORMAT_SLUGS = [
        'cc' => 'CC',
        'll' => 'LL',
        'sage' => 'SAGE',
        'gage' => 'GAGE',
    ];

    /**
     * Undefined-reference sentinel used throughout fablazing's pooled payload.
     */
    private const POOL_UNDEFINED = -5;

    private int $created = 0;

    private int $updated = 0;

    private int $flagged = 0;

    /**
     * Hero cards for this run, keyed by normalized name.
     *
     * @var array<string, string>
     */
    private array $heroCardIdsByName = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $canonicalFormats = Format::pluck('abbreviation')->all();

        if ($canonicalFormats === []) {
            $this->error('No canonical formats found; run the FormatSeeder first.');

            return Command::FAILURE;
        }

        $this->heroCardIdsByName = $this->loadHeroCardIdsByName();

        if ($this->heroCardIdsByName === []) {
            $this->error('No hero cards found; run data:ingest-cards first.');

            return Command::FAILURE;
        }

        $fabtcgmetaOk = $this->ingestFabtcgmeta($canonicalFormats);
        $fablazingOk = $this->ingestFablazing($canonicalFormats);

        $this->newLine(2);
        $this->info("Meta ingest complete: {$this->created} created, {$this->updated} updated, {$this->flagged} flagged.");

        if (! $fabtcgmetaOk && ! $fablazingOk) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Load hero-type cards keyed by their normalized name.
     *
     * @return array<string, string>
     */
    private function loadHeroCardIdsByName(): array
    {
        $heroTypeId = CardType::where('name', 'hero')->value('id');

        if ($heroTypeId === null) {
            return [];
        }

        return Card::where('card_type_id', $heroTypeId)
            ->pluck('id', 'name')
            ->mapWithKeys(fn (string $id, string $name): array => [$this->normalizeHeroName($name) => $id])
            ->all();
    }

    /**
     * Normalize a hero name for matching: lowercase, collapse punctuation and
     * whitespace to single spaces, trim. Applied identically to card names
     * and to both sources' incoming hero identifiers.
     */
    private function normalizeHeroName(string $name): string
    {
        $normalized = mb_strtolower($name);
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * Resolve the fabtcgmeta format codes whose canonical target is currently
     * seeded in `formats`, keyed by source code.
     *
     * @param  array<int, string>  $canonicalFormats
     * @return array<string, string>
     */
    private function activeFabtcgmetaFormats(array $canonicalFormats): array
    {
        return array_filter(
            self::FABTCGMETA_FORMAT_CODES,
            fn (string $abbreviation): bool => in_array($abbreviation, $canonicalFormats, true)
        );
    }

    /**
     * Resolve the fablazing route slugs whose canonical target is currently
     * seeded in `formats`, keyed by slug.
     *
     * @param  array<int, string>  $canonicalFormats
     * @return array<string, string>
     */
    private function activeFablazingFormats(array $canonicalFormats): array
    {
        return array_filter(
            self::FABLAZING_FORMAT_SLUGS,
            fn (string $abbreviation): bool => in_array($abbreviation, $canonicalFormats, true)
        );
    }

    /**
     * Ingest every active format from fabtcgmeta.com. Returns whether the
     * source completed without a total, source-level failure.
     *
     * @param  array<int, string>  $canonicalFormats
     */
    private function ingestFabtcgmeta(array $canonicalFormats): bool
    {
        $baseUrl = $this->resolveFabtcgmetaUrl();
        $active = $this->activeFabtcgmetaFormats($canonicalFormats);

        if ($active === []) {
            return true;
        }

        $ok = false;

        foreach ($active as $code => $abbreviation) {
            $fetchedAt = now();

            try {
                $performances = $this->fetchFabtcgmetaPerformances($baseUrl, $code);
            } catch (Throwable $e) {
                Log::warning('Failed to fetch fabtcgmeta hero performance', [
                    'format_code' => $code,
                    'message' => $e->getMessage(),
                ]);
                $this->warn("Failed to fetch fabtcgmeta hero performance for {$code}: {$e->getMessage()}");
                $this->flagged++;

                continue;
            }

            $ok = true;

            foreach ($performances as $performance) {
                $this->persistFabtcgmetaObservation($performance, $abbreviation, $fetchedAt);
            }
        }

        return $ok;
    }

    /**
     * Resolve the fabtcgmeta hero-performance API base URL for this run.
     */
    private function resolveFabtcgmetaUrl(): string
    {
        $url = $this->option('fabtcgmeta-url');

        return is_string($url) && $url !== '' ? $url : self::FABTCGMETA_URL;
    }

    /**
     * Fetch the full hero roster for one fabtcgmeta format code.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchFabtcgmetaPerformances(string $baseUrl, string $code): array
    {
        $json = Http::timeout(30)
            ->withHeaders(['User-Agent' => self::USER_AGENT])
            ->get($baseUrl, [
                'format' => $code,
                'limit' => 200,
                'sortBy' => 'winrate',
                'sortOrder' => 'desc',
                'minGames' => 1,
            ])
            ->throw()
            ->json();

        $performances = $json['heroPerformances'] ?? [];

        return is_array($performances) ? $performances : [];
    }

    /**
     * Match, parse, and persist a single fabtcgmeta hero-performance entry.
     *
     * @param  array<string, mixed>  $performance
     */
    private function persistFabtcgmetaObservation(array $performance, string $format, CarbonInterface $fetchedAt): void
    {
        $slug = $performance['heroName'] ?? null;

        if (! is_string($slug) || $slug === '') {
            Log::warning('fabtcgmeta entry missing heroName', ['format' => $format]);
            $this->warn('fabtcgmeta entry missing heroName');
            $this->flagged++;

            return;
        }

        $normalized = $this->normalizeHeroName(str_replace('_', ' ', $slug));
        $heroId = $this->heroCardIdsByName[$normalized] ?? null;

        if ($heroId === null) {
            Log::warning('No hero card match for scraped hero', [
                'source' => self::SOURCE_FABTCGMETA,
                'format' => $format,
                'hero_name' => $slug,
            ]);
            $this->warn("No hero card match for fabtcgmeta hero: {$slug}");
            $this->flagged++;

            return;
        }

        $winRate = $this->normalizeWinRate($performance['winrate'] ?? null);

        if ($winRate === null) {
            Log::warning('fabtcgmeta entry has a missing or invalid win rate', [
                'hero_name' => $slug,
                'format' => $format,
                'winrate' => $performance['winrate'] ?? null,
            ]);
            $this->warn("Missing or invalid win rate for fabtcgmeta hero: {$slug}");
            $this->flagged++;

            return;
        }

        $sampleSize = $performance['sampleSize'] ?? $performance['totalGames'] ?? null;
        $sampleSize = is_numeric($sampleSize) ? (int) $sampleSize : null;

        $this->persistSnapshot($heroId, $format, self::SOURCE_FABTCGMETA, $winRate, $sampleSize, $fetchedAt);
    }

    /**
     * Cast and range-check a win-rate value. Returns null for missing,
     * non-numeric, or out-of-[0,1]-range input.
     */
    private function normalizeWinRate(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        if ($float < 0.0 || $float > 1.0) {
            return null;
        }

        return $float;
    }

    /**
     * Ingest every active format from fablazing.com. Returns whether the
     * source completed without a total, source-level failure.
     *
     * @param  array<int, string>  $canonicalFormats
     */
    private function ingestFablazing(array $canonicalFormats): bool
    {
        $baseUrl = $this->resolveFablazingUrl();
        $active = $this->activeFablazingFormats($canonicalFormats);

        if ($active === []) {
            return true;
        }

        $ok = false;

        foreach ($active as $slug => $abbreviation) {
            $fetchedAt = now();

            try {
                $html = Http::timeout(30)
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->get("{$baseUrl}/{$slug}")
                    ->throw()
                    ->body();

                $heroes = $this->decodeFablazingHeroes($html, $slug);
            } catch (Throwable $e) {
                Log::warning('Failed to fetch or decode fablazing meta page', [
                    'slug' => $slug,
                    'message' => $e->getMessage(),
                ]);
                $this->warn("Failed to fetch or decode fablazing meta page for {$slug}: {$e->getMessage()}");
                $this->flagged++;

                continue;
            }

            $ok = true;

            foreach ($heroes as $hero) {
                $this->persistFablazingObservation($hero, $abbreviation, $fetchedAt);
            }
        }

        return $ok;
    }

    /**
     * Resolve the fablazing meta base URL for this run.
     */
    private function resolveFablazingUrl(): string
    {
        $url = $this->option('fablazing-url');

        return is_string($url) && $url !== '' ? rtrim($url, '/') : self::FABLAZING_BASE_URL;
    }

    /**
     * Extract and resolve the hero list embedded in a fablazing meta page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function decodeFablazingHeroes(string $html, string $slug): array
    {
        $marker = 'window.__remixContext.streamController.enqueue("';
        $start = strpos($html, $marker);

        if ($start === false) {
            throw new RuntimeException('No streamController.enqueue payload found in fablazing response');
        }

        $start += strlen($marker);
        $endScript = strpos($html, '</script>', $start);

        if ($endScript === false) {
            throw new RuntimeException('No closing </script> tag found after fablazing payload');
        }

        $segment = rtrim(substr($html, $start, $endScript - $start));

        if (str_ends_with($segment, ';')) {
            $segment = substr($segment, 0, -1);
        }

        if (! str_ends_with($segment, ')')) {
            throw new RuntimeException('fablazing payload did not end with a closing )');
        }

        $segment = substr($segment, 0, -1);

        if (! str_ends_with($segment, '"')) {
            throw new RuntimeException('fablazing payload did not end with a closing quote');
        }

        $segment = substr($segment, 0, -1);

        $unescaped = json_decode('"'.$segment.'"');

        if (! is_string($unescaped)) {
            throw new RuntimeException('Failed to unescape fablazing payload string');
        }

        $pool = json_decode($unescaped, true);

        if (! is_array($pool)) {
            throw new RuntimeException('Failed to decode fablazing payload pool');
        }

        $root = $this->resolvePoolValue($pool, 0);

        if (! is_array($root)) {
            throw new RuntimeException('fablazing payload root did not resolve to an array');
        }

        $heroes = $root['loaderData']["routes/meta.{$slug}"]['metaData']['heroes'] ?? null;

        return is_array($heroes) ? $heroes : [];
    }

    /**
     * Resolve a value out of fablazing's index-addressed value pool.
     * Objects and arrays store their entries as further pool indices;
     * terminal scalars are returned as-is once reached directly.
     */
    private function resolvePoolValue(array $pool, ?int $idx): mixed
    {
        if ($idx === null || $idx === self::POOL_UNDEFINED || ! array_key_exists($idx, $pool)) {
            return null;
        }

        $value = $pool[$idx];

        if (is_array($value) && $this->isAssocArray($value)) {
            $result = [];

            foreach ($value as $keyToken => $valueIdx) {
                if (! is_string($keyToken) || ! str_starts_with($keyToken, '_')) {
                    continue;
                }

                $key = $this->resolvePoolValue($pool, (int) substr($keyToken, 1));

                if (! is_string($key)) {
                    continue;
                }

                $result[$key] = $this->resolvePoolValue($pool, is_int($valueIdx) ? $valueIdx : null);
            }

            return $result;
        }

        if (is_array($value)) {
            return array_map(
                fn ($v) => $this->resolvePoolValue($pool, is_int($v) ? $v : null),
                $value
            );
        }

        return $value;
    }

    /**
     * Determine whether a decoded JSON array is an object (string keys) or
     * a list (sequential integer keys).
     */
    private function isAssocArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * Match, parse, and persist a single fablazing hero entry.
     *
     * @param  array<string, mixed>  $hero
     */
    private function persistFablazingObservation(array $hero, string $format, CarbonInterface $fetchedAt): void
    {
        $name = $hero['hero_name'] ?? null;

        if (! is_string($name) || $name === '') {
            Log::warning('fablazing entry missing hero_name', ['format' => $format]);
            $this->warn('fablazing entry missing hero_name');
            $this->flagged++;

            return;
        }

        $normalized = $this->normalizeHeroName($name);
        $heroId = $this->heroCardIdsByName[$normalized] ?? null;

        if ($heroId === null) {
            Log::warning('No hero card match for scraped hero', [
                'source' => self::SOURCE_FABLAZING,
                'format' => $format,
                'hero_name' => $name,
            ]);
            $this->warn("No hero card match for fablazing hero: {$name}");
            $this->flagged++;

            return;
        }

        $winRate = $this->normalizeWinRate($hero['win_rate'] ?? null);

        if ($winRate === null) {
            Log::warning('fablazing entry has a missing or invalid win rate', [
                'hero_name' => $name,
                'format' => $format,
                'win_rate' => $hero['win_rate'] ?? null,
            ]);
            $this->warn("Missing or invalid win rate for fablazing hero: {$name}");
            $this->flagged++;

            return;
        }

        $sampleSize = $hero['count'] ?? null;
        $sampleSize = is_numeric($sampleSize) ? (int) $sampleSize : null;

        $this->persistSnapshot($heroId, $format, self::SOURCE_FABLAZING, $winRate, $sampleSize, $fetchedAt);
    }

    /**
     * Write (or overwrite) the meta_snapshots row for one (hero, format,
     * source) observation, tallying created vs. updated.
     */
    private function persistSnapshot(string $heroId, string $format, string $source, ?float $winRate, ?int $sampleSize, CarbonInterface $fetchedAt): void
    {
        DB::transaction(function () use ($heroId, $format, $source, $winRate, $sampleSize, $fetchedAt): void {
            $existing = MetaSnapshot::where('hero_id', $heroId)
                ->where('format', $format)
                ->where('source', $source)
                ->first();

            MetaSnapshot::updateOrCreate(
                ['hero_id' => $heroId, 'format' => $format, 'source' => $source],
                ['win_rate' => $winRate, 'sample_size' => $sampleSize, 'fetched_at' => $fetchedAt],
            );

            if ($existing === null) {
                $this->created++;
            } else {
                $this->updated++;
            }
        });
    }
}
