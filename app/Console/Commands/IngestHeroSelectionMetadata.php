<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\CardLegality;
use App\Models\CardType;
use App\Models\Format;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('data:ingest-hero-selection {--api-url= : Override the fabtcg hero API URL}')]
#[Description('Ingest official hero playstyle tags, lore, and format legality from fabtcg.com/hero-selection into hero_profiles, heroes, and card_legality.')]
class IngestHeroSelectionMetadata extends Command
{
    public const API_URL = 'https://fabtcg.com/api/fab/v2/heroes/?lang=en';

    public const USER_AGENT = 'YaFaBa-Data-Ingest/1.0 (+https://github.com/joshevensen/yafaba-api)';

    /**
     * fabtcg's own format keys, mapped to this app's canonical abbreviation.
     * Not the canonical format list itself — filtered against `formats` at
     * runtime. `blitz`, `commoner`, and `ultimate-pit-fight` have no
     * corresponding row in `formats` and are deliberately left unmapped.
     *
     * @var array<string, string>
     */
    private const FORMAT_KEY_MAP = [
        'cc' => 'CC',
        'living-legend' => 'LL',
        'project-blue' => 'SAGE',
    ];

    private int $heroesUpdated = 0;

    private int $legalityCreated = 0;

    private int $legalityUpdated = 0;

    private int $flagged = 0;

    /**
     * Unmapped fabtcg format keys already warned about this run, so a key
     * shared by every hero is flagged once instead of once per hero.
     *
     * @var array<string, true>
     */
    private array $warnedUnmappedFormatKeys = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $heroTypeId = CardType::where('name', 'hero')->value('id');

        if ($heroTypeId === null) {
            $this->error('No hero card type found; run data:ingest-cards first.');

            return Command::FAILURE;
        }

        if (! Card::where('card_type_id', $heroTypeId)->exists()) {
            $this->error('No hero cards found; run data:ingest-cards first.');

            return Command::FAILURE;
        }

        try {
            $heroes = $this->fetchHeroes();
        } catch (Throwable $e) {
            $this->error("Failed to fetch fabtcg hero data: {$e->getMessage()}");

            return Command::FAILURE;
        }

        if ($heroes === null) {
            $this->error('fabtcg hero data was empty or malformed.');

            return Command::FAILURE;
        }

        $formatIdsByKey = $this->loadFormatIdsByKey();

        $this->withProgressBar($heroes, function (array $hero) use ($heroTypeId, $formatIdsByKey): void {
            $this->ingestHero($hero, $heroTypeId, $formatIdsByKey);
        });

        $this->newLine(2);
        $this->info("Hero selection ingest complete: {$this->heroesUpdated} heroes updated, {$this->legalityCreated} legality rows created, {$this->legalityUpdated} legality rows updated, {$this->flagged} flagged.");

        return Command::SUCCESS;
    }

    /**
     * Resolve the fabtcg hero API URL for this run.
     */
    private function resolveApiUrl(): string
    {
        $url = $this->option('api-url');

        return is_string($url) && $url !== '' ? $url : self::API_URL;
    }

    /**
     * Fetch and validate the fabtcg hero payload. Returns null if the
     * decoded payload is not an array or has no non-empty `heroes` list.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchHeroes(): ?array
    {
        $payload = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(60)
            ->get($this->resolveApiUrl())
            ->throw()
            ->json();

        if (! is_array($payload)) {
            return null;
        }

        $heroes = $payload['heroes'] ?? null;

        if (! is_array($heroes) || $heroes === []) {
            return null;
        }

        return $heroes;
    }

    /**
     * Resolve the `formats` row ids for every mapped fabtcg format key,
     * keyed by fabtcg key. A mapped abbreviation with no seeded `Format`
     * row is warned once and simply absent from the result.
     *
     * @return array<string, string>
     */
    private function loadFormatIdsByKey(): array
    {
        $idsByAbbreviation = Format::whereIn('abbreviation', array_values(self::FORMAT_KEY_MAP))
            ->pluck('id', 'abbreviation');

        $idsByKey = [];

        foreach (self::FORMAT_KEY_MAP as $key => $abbreviation) {
            $id = $idsByAbbreviation[$abbreviation] ?? null;

            if ($id === null) {
                Log::warning('Skipping legality sync for unseeded format', ['format' => $abbreviation]);
                $this->warn("Skipping legality sync for unseeded format: {$abbreviation}");
                $this->flagged++;

                continue;
            }

            $idsByKey[$key] = $id;
        }

        return $idsByKey;
    }

    /**
     * Ingest one fabtcg hero object: resolve its matching hero card(s), then
     * apply playstyle tags, lore, and legality to each.
     *
     * @param  array<string, mixed>  $hero
     * @param  array<string, string>  $formatIdsByKey
     */
    private function ingestHero(array $hero, string $heroTypeId, array $formatIdsByKey): void
    {
        $name = $this->buildHeroName($hero);
        $cards = $this->resolveHeroCards($name, $heroTypeId);

        if ($cards->isEmpty()) {
            Log::warning('Unmatched fabtcg hero name', ['name' => $name]);
            $this->warn("Unmatched fabtcg hero name: {$name}");
            $this->flagged++;

            return;
        }

        foreach ($cards as $card) {
            $this->ingestHeroCard($card, $hero, $name, $formatIdsByKey);
        }
    }

    /**
     * Build the space-joined "title subtitle" name fabtcg heroes resolve
     * to, matching what `IngestFabCubeCards::seedHeroFor()` stored as
     * `Hero.name` / `Card.name`.
     *
     * @param  array<string, mixed>  $hero
     */
    private function buildHeroName(array $hero): string
    {
        $title = trim((string) ($hero['title'] ?? ''));
        $subtitle = trim((string) ($hero['subtitle'] ?? ''));

        return trim("{$title} {$subtitle}");
    }

    /**
     * Resolve every hero card matching the given name, case-insensitively,
     * restricted to the hero card type.
     *
     * @return \Illuminate\Support\Collection<int, Card>
     */
    private function resolveHeroCards(string $name, string $heroTypeId)
    {
        return Card::where('card_type_id', $heroTypeId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->get();
    }

    /**
     * Apply playstyle tags, lore, and legality writes for one resolved hero
     * card against one fabtcg hero object.
     *
     * @param  array<string, mixed>  $hero
     * @param  array<string, string>  $formatIdsByKey
     */
    private function ingestHeroCard(Card $card, array $hero, string $name, array $formatIdsByKey): void
    {
        DB::transaction(function () use ($card, $hero, $name, $formatIdsByKey): void {
            $updated = false;

            $updated = $this->applyPlaystyleTags($card, $hero, $name) || $updated;
            $updated = $this->applyLore($card, $hero, $name) || $updated;
            $updated = $this->syncOfficialLegality($card, $hero, $name, $formatIdsByKey) || $updated;

            if ($updated) {
                $this->heroesUpdated++;
            }
        });
    }

    /**
     * Normalize and write `hero_profiles.playstyle_tags` from the hero's
     * `playstyle` array. Leaves existing tags untouched (never overwrites
     * curated data with an empty list) when the field is missing, not an
     * array, or normalizes to nothing.
     *
     * @param  array<string, mixed>  $hero
     */
    private function applyPlaystyleTags(Card $card, array $hero, string $name): bool
    {
        $heroProfile = $card->heroProfile;

        if ($heroProfile === null) {
            Log::warning('Hero card has no hero profile', ['name' => $name]);
            $this->warn("Hero card has no hero profile: {$name}");
            $this->flagged++;

            return false;
        }

        $raw = $hero['playstyle'] ?? null;
        $tags = is_array($raw) ? array_values(array_unique(array_filter(
            array_map(fn ($tag): string => trim((string) $tag), $raw),
            fn (string $tag): bool => $tag !== ''
        ))) : [];

        if ($tags === []) {
            Log::warning('Missing fabtcg playstyle tags', ['name' => $name]);
            $this->warn("Missing fabtcg playstyle tags: {$name}");
            $this->flagged++;

            return false;
        }

        $heroProfile->playstyle_tags = $tags;
        $heroProfile->save();

        return true;
    }

    /**
     * Normalize and write `heroes.lore` from the hero's HTML `bio`. Leaves
     * existing lore untouched when the bio is missing or normalizes to
     * nothing.
     *
     * @param  array<string, mixed>  $hero
     */
    private function applyLore(Card $card, array $hero, string $name): bool
    {
        $heroProfile = $card->heroProfile;

        if ($heroProfile === null) {
            return false;
        }

        $heroRecord = $heroProfile->hero;

        if ($heroRecord === null) {
            return false;
        }

        $bio = (string) ($hero['bio'] ?? '');
        $stripped = strip_tags($bio);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lore = $this->normalizeText($decoded);

        if ($lore === '') {
            Log::warning('Missing fabtcg hero bio', ['name' => $name]);
            $this->warn("Missing fabtcg hero bio: {$name}");
            $this->flagged++;

            return false;
        }

        $heroRecord->lore = $lore;
        $heroRecord->save();

        return true;
    }

    /**
     * Sync `card_legality` rows for the mapped fabtcg format keys present
     * on the hero's `format` object. Unmapped keys are flagged once per
     * run; an unrecognized status flags and skips that single format
     * without aborting the hero. This is an official-source overlay:
     * it deliberately overwrites whatever `IngestFabCubeCards::syncLegality`
     * wrote, and never deletes rows.
     *
     * @param  array<string, mixed>  $hero
     * @param  array<string, string>  $formatIdsByKey
     */
    private function syncOfficialLegality(Card $card, array $hero, string $name, array $formatIdsByKey): bool
    {
        $formats = $hero['format'] ?? null;

        if (! is_array($formats)) {
            return false;
        }

        $updated = false;

        foreach ($formats as $key => $entry) {
            if (! is_string($key)) {
                continue;
            }

            if (! array_key_exists($key, self::FORMAT_KEY_MAP)) {
                $this->flagUnmappedFormatKeyOnce($key);

                continue;
            }

            $formatId = $formatIdsByKey[$key] ?? null;

            if ($formatId === null) {
                continue;
            }

            $rawStatus = is_array($entry) ? ($entry['status'] ?? null) : null;
            $status = match ($rawStatus) {
                'legal' => 'legal',
                'not_legal' => 'not_legal',
                default => null,
            };

            if ($status === null) {
                Log::warning('Unrecognized fabtcg legality status', ['name' => $name, 'key' => $key, 'status' => $rawStatus]);
                $this->warn("Unrecognized fabtcg legality status for {$name} ({$key}): ".var_export($rawStatus, true));
                $this->flagged++;

                continue;
            }

            $this->persistLegality($card->id, $formatId, $status);
            $updated = true;
        }

        return $updated;
    }

    /**
     * Warn about an unmapped fabtcg format key exactly once per run.
     */
    private function flagUnmappedFormatKeyOnce(string $key): void
    {
        if (isset($this->warnedUnmappedFormatKeys[$key])) {
            return;
        }

        $this->warnedUnmappedFormatKeys[$key] = true;

        Log::warning('Unmapped fabtcg format key', ['key' => $key]);
        $this->warn("Unmapped fabtcg format key: {$key}");
        $this->flagged++;
    }

    /**
     * Write (or overwrite) the card_legality row for one (card, format)
     * pair, tallying created vs. updated.
     */
    private function persistLegality(string $cardId, string $formatId, string $status): void
    {
        $existing = CardLegality::where('card_id', $cardId)->where('format_id', $formatId)->first();

        CardLegality::updateOrCreate(
            ['card_id' => $cardId, 'format_id' => $formatId],
            ['status' => $status],
        );

        if ($existing === null) {
            $this->legalityCreated++;
        } else {
            $this->legalityUpdated++;
        }
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
}
