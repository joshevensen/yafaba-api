<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\CardPrinting;
use App\Services\CardImageMirror;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('data:ingest-printings {--url= : Override the cardvault API base URL}')]
#[Description('Ingest print variations and mirror card art from the unofficial cardvault API into card_printings.')]
class IngestCardvaultPrintings extends Command
{
    public const BASE_URL = 'https://api.cardvault.fabtcg.com/carddb/api/v1/';

    /**
     * Cache of card name (lowercased, trimmed) => matched cardvault card_id slugs for this run.
     *
     * @var array<string, array<int, string>>
     */
    private array $searchCache = [];

    /**
     * Cache of cardvault card_id slug => that card_id's card_prints array for this run.
     *
     * @var array<string, array<int, mixed>>
     */
    private array $detailCache = [];

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $flagged = 0;

    public function __construct(private readonly CardImageMirror $imageMirror)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $baseUrl = $this->resolveBaseUrl();

        $cards = Card::all(['id', 'name']);

        if ($cards->isEmpty()) {
            $this->error('No cards found; run data:ingest-cards first.');

            return Command::FAILURE;
        }

        $this->withProgressBar($cards, function (Card $card) use ($baseUrl): void {
            $this->ingestCard($card, $baseUrl);
        });

        $this->newLine(2);
        $this->info("Ingest complete: {$this->created} created, {$this->updated} updated, {$this->skipped} skipped, {$this->flagged} flagged.");

        return Command::SUCCESS;
    }

    /**
     * Resolve the base URL for this run, normalized to end with a trailing slash.
     */
    private function resolveBaseUrl(): string
    {
        $url = $this->option('url');
        $url = is_string($url) && $url !== '' ? $url : self::BASE_URL;

        return rtrim($url, '/').'/';
    }

    /**
     * Resolve a single card against cardvault and ingest all of its matched print records.
     */
    private function ingestCard(Card $card, string $baseUrl): void
    {
        try {
            $cardIds = $this->searchCardIds($card, $baseUrl);
        } catch (Throwable $e) {
            Log::warning('Failed to search cardvault for card', ['name' => $card->name, 'message' => $e->getMessage()]);
            $this->warn("Failed to search cardvault for {$card->name}: {$e->getMessage()}");
            $this->flagged++;

            return;
        }

        if ($cardIds === []) {
            Log::warning('No cardvault match for card', ['name' => $card->name]);
            $this->warn("No cardvault match for {$card->name}");
            $this->flagged++;

            return;
        }

        foreach ($cardIds as $cardId) {
            try {
                $prints = $this->detailCardPrints($cardId, $baseUrl);
            } catch (Throwable $e) {
                Log::warning('Failed to fetch cardvault detail', ['card_id' => $cardId, 'message' => $e->getMessage()]);
                $this->warn("Failed to fetch cardvault detail for {$cardId}: {$e->getMessage()}");
                $this->flagged++;

                continue;
            }

            if ($prints === []) {
                Log::warning('No card_prints in cardvault detail', ['card_id' => $cardId]);
                $this->flagged++;

                continue;
            }

            foreach ($prints as $print) {
                if (! is_array($print)) {
                    continue;
                }

                $this->ingestPrint($card, $print);
            }
        }
    }

    /**
     * Resolve (and cache) the cardvault card_id slugs matching a card's exact name.
     *
     * @return array<int, string>
     */
    private function searchCardIds(Card $card, string $baseUrl): array
    {
        $cacheKey = strtolower(trim($card->name));

        if (isset($this->searchCache[$cacheKey])) {
            return $this->searchCache[$cacheKey];
        }

        $results = [];
        $url = $baseUrl.'advanced-search/?q='.urlencode($card->name).'&page_size=25&orderby=name';

        while ($url !== null) {
            $json = Http::timeout(30)->get($url)->throw()->json();

            $pageResults = $json['results'] ?? [];
            $pageResults = is_array($pageResults) ? $pageResults : [];
            $results = array_merge($results, $pageResults);

            $url = $json['next'] ?? null;
            $url = is_string($url) && $url !== '' ? $url : null;
        }

        $cardIds = [];
        foreach ($results as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $printedName = $entry['printed_name'] ?? '';
            $printedName = is_string($printedName) ? $printedName : '';

            if (strcasecmp($printedName, $card->name) !== 0) {
                continue;
            }

            $cardId = $entry['card_id'] ?? null;

            if (is_string($cardId) && $cardId !== '') {
                $cardIds[] = $cardId;
            }
        }

        $cardIds = array_values(array_unique($cardIds));

        $this->searchCache[$cacheKey] = $cardIds;

        return $cardIds;
    }

    /**
     * Resolve (and cache) the card_prints array for a cardvault card_id slug.
     *
     * @return array<int, mixed>
     */
    private function detailCardPrints(string $cardId, string $baseUrl): array
    {
        if (isset($this->detailCache[$cardId])) {
            return $this->detailCache[$cardId];
        }

        $url = $baseUrl."card_id/{$cardId}/";
        $json = Http::timeout(30)->get($url)->throw()->json();

        $prints = $json['results'][0]['card_prints'] ?? [];
        $prints = is_array($prints) ? $prints : [];

        $this->detailCache[$cardId] = $prints;

        return $prints;
    }

    /**
     * Ingest a single cardvault print entry for the given card, mirroring its
     * art (outside any transaction) before writing the card_printings row.
     *
     * @param  array<string, mixed>  $print
     */
    private function ingestPrint(Card $card, array $print): void
    {
        $printId = $print['print_id'] ?? null;
        $setCode = $print['print_set']['set_code'] ?? null;

        if (! is_string($printId) || $printId === '' || ! is_string($setCode) || $setCode === '') {
            Log::warning('Skipping print with missing print_id or set_code', [
                'card_id' => $card->id,
                'print_id' => $printId,
                'set_code' => $setCode,
            ]);
            $this->warn("Skipping print with missing print_id or set_code for {$card->name}");
            $this->flagged++;

            return;
        }

        $rarity = $print['rarity'] ?? null;
        $face = $print['faces'][0] ?? [];
        $face = is_array($face) ? $face : [];
        $finish = $face['finish_type'] ?? null;
        $artType = $face['art_type'] ?? null;
        $artVariant = $artType === 'regular' ? null : $artType;
        $sourceImageUrl = $face['image']['normal'] ?? null;
        $sourceImageUrl = is_string($sourceImageUrl) && $sourceImageUrl !== '' ? $sourceImageUrl : null;

        $existing = CardPrinting::where('cardvault_print_id', $printId)->first();

        [$imageUrl, $imageHash] = $this->resolveImage($printId, $sourceImageUrl, $existing);

        DB::transaction(function () use ($card, $printId, $setCode, $rarity, $finish, $artVariant, $imageUrl, $imageHash): void {
            CardPrinting::updateOrCreate(
                ['cardvault_print_id' => $printId],
                [
                    'card_id' => $card->id,
                    'set_code' => $setCode,
                    'rarity' => $rarity,
                    'finish' => $finish,
                    'art_variant' => $artVariant,
                    'image_url' => $imageUrl,
                    'image_source_hash' => $imageHash,
                ],
            );
        });

        if ($existing === null) {
            $this->created++;
        } else {
            $this->updated++;
        }
    }

    /**
     * Resolve the image URL and source hash to persist for a print, mirroring
     * art via CardImageMirror only when necessary. Returns [imageUrl, imageHash].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveImage(string $printId, ?string $sourceImageUrl, ?CardPrinting $existing): array
    {
        if ($sourceImageUrl === null) {
            Log::warning('Print has no source image', ['print_id' => $printId]);
            $this->warn("Print has no source image: {$printId}");
            $this->flagged++;

            return [$existing->image_url ?? null, $existing->image_source_hash ?? null];
        }

        $hash = hash('sha256', $sourceImageUrl);

        if ($existing !== null && $existing->image_source_hash === $hash) {
            return [$existing->image_url, $existing->image_source_hash];
        }

        $mirroredUrl = $this->imageMirror->mirror($sourceImageUrl, $printId);

        if ($mirroredUrl === null) {
            Log::warning('Failed to mirror card image', ['print_id' => $printId, 'url' => $sourceImageUrl]);
            $this->warn("Failed to mirror card image for {$printId}");
            $this->flagged++;

            return [$existing->image_url ?? null, $existing->image_source_hash ?? null];
        }

        return [$mirroredUrl, $hash];
    }
}
