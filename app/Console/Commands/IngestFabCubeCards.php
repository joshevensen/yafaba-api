<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\CardClass;
use App\Models\CardLegality;
use App\Models\CardType;
use App\Models\Hero;
use App\Models\HeroProfile;
use App\Models\Keyword;
use App\Models\Talent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('data:ingest-cards {--url= : Override the upstream card.json URL}')]
#[Description('Ingest card data from the-fab-cube/flesh-and-blood-cards into the core card dataset.')]
class IngestFabCubeCards extends Command
{
    public const SOURCE_URL = 'https://raw.githubusercontent.com/the-fab-cube/flesh-and-blood-cards/develop/json/english/card.json';

    /**
     * Priority-ordered token(s) => card_types.name classification map.
     *
     * @var array<int, array{0: array<int, string>, 1: string}>
     */
    private const TYPE_CLASSIFICATION = [
        [['Hero', 'Demi-Hero'], 'hero'],
        [['Weapon'], 'weapon'],
        [['Equipment'], 'equipment'],
        [['Attack Reaction'], 'attack_reaction'],
        [['Defense Reaction'], 'defense_reaction'],
        [['Instant'], 'instant'],
        [['Action'], 'action'],
        [['Resource'], 'resource_token'],
        [['Token'], 'resource_token'],
    ];

    /**
     * @var array<int, string>
     */
    private const CLASS_TOKENS = [
        'Warrior', 'Ninja', 'Wizard', 'Guardian', 'Brute', 'Runeblade', 'Ranger',
        'Illusionist', 'Mechanologist', 'Necromancer', 'Assassin', 'Pirate', 'Generic',
        'Mercenary', 'Merchant', 'Adjudicator', 'Bard', 'Mentor', 'Pit-Fighter', 'Thief',
        'Shapeshifter',
    ];

    /**
     * @var array<int, string>
     */
    private const TALENT_TOKENS = [
        'Draconic', 'Light', 'Shadow', 'Elemental', 'Ice', 'Lightning', 'Earth', 'Royal',
        'Mystic', 'Chaos',
    ];

    /**
     * @var array<string, array{legal: string, banned: string, suspended: ?string}>
     */
    private const LEGALITY_FORMATS = [
        'SAGE' => ['legal' => 'silver_age_legal', 'banned' => 'silver_age_banned', 'suspended' => null],
        'CC' => ['legal' => 'cc_legal', 'banned' => 'cc_banned', 'suspended' => 'cc_suspended'],
        'Blitz' => ['legal' => 'blitz_legal', 'banned' => 'blitz_banned', 'suspended' => 'blitz_suspended'],
        'Commoner' => ['legal' => 'commoner_legal', 'banned' => 'commoner_banned', 'suspended' => 'commoner_suspended'],
    ];

    /**
     * @var array<string, int>
     */
    private array $cardTypeIds = [];

    /**
     * @var array<string, int>
     */
    private array $classIds = [];

    /**
     * @var array<string, int>
     */
    private array $talentIds = [];

    /**
     * @var array<string, int>
     */
    private array $keywordIds = [];

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $flagged = 0;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->seedCardTypes();

        try {
            $records = $this->fetchRecords();
        } catch (Throwable $e) {
            $this->error("Failed to fetch card data: {$e->getMessage()}");

            return Command::FAILURE;
        }

        if (! is_array($records) || $records === []) {
            $this->error('Upstream card data was empty or malformed.');

            return Command::FAILURE;
        }

        $this->withProgressBar($records, function (array $record): void {
            DB::transaction(function () use ($record): void {
                $this->ingestRecord($record);
            });
        });

        $this->newLine(2);
        $this->info("Ingest complete: {$this->created} created, {$this->updated} updated, {$this->skipped} skipped, {$this->flagged} flagged.");

        return Command::SUCCESS;
    }

    /**
     * Seed the fixed set of card types and cache their ids in memory.
     */
    private function seedCardTypes(): void
    {
        $names = [
            'hero', 'weapon', 'equipment', 'action', 'instant', 'attack_reaction',
            'defense_reaction', 'resource_token', 'other',
        ];

        foreach ($names as $order => $name) {
            $cardType = CardType::updateOrCreate(
                ['name' => $name],
                ['display_order' => $order + 1],
            );

            $this->cardTypeIds[$name] = $cardType->id;
        }
    }

    /**
     * Fetch the upstream card records. Returns whatever the response decodes
     * to — validated as a non-empty array of records by the caller.
     */
    private function fetchRecords(): mixed
    {
        $url = $this->option('url') ?: self::SOURCE_URL;

        return Http::timeout(180)->get($url)->throw()->json();
    }

    /**
     * Ingest a single upstream record.
     *
     * @param  array<string, mixed>  $record
     */
    private function ingestRecord(array $record): void
    {
        $sourceId = $record['unique_id'] ?? null;

        if (! is_string($sourceId) || $sourceId === '') {
            $name = $record['name'] ?? 'unknown';
            Log::warning('Skipping card with missing unique_id', ['name' => $name]);
            $this->warn("Skipping card with missing unique_id: {$name}");
            $this->flagged++;

            return;
        }

        $sourceHash = $this->sourceHashFor($record);
        $card = Card::where('source_id', $sourceId)->first();

        if ($card !== null && $card->source_hash === $sourceHash) {
            $this->skipped++;

            return;
        }

        $types = $record['types'] ?? [];
        $types = is_array($types) ? $types : [];
        $cardTypeName = $this->classifyCardType($types, $record);
        $cardTypeId = $this->cardTypeIds[$cardTypeName];

        $age = null;
        if ($cardTypeName === 'hero') {
            $age = in_array('Young', $types, true) ? 'young' : 'adult';
        }

        $cost = $record['cost'] ?? null;
        $cost = (is_string($cost) && $cost === '') ? null : $cost;

        $functionalText = $record['functional_text_plain'] ?? null;
        $functionalText = (is_string($functionalText) && $functionalText === '') ? null : $functionalText;

        $attributes = [
            'name' => $record['name'] ?? null,
            'card_type_id' => $cardTypeId,
            'pitch_value' => $this->toNullableInt($record['pitch'] ?? null),
            'cost' => $cost,
            'power' => $this->toNullableInt($record['power'] ?? null),
            'defense' => $this->toNullableInt($record['defense'] ?? null),
            'functional_text' => $functionalText,
            'age' => $age,
            'source_hash' => $sourceHash,
            'updated_at' => now(),
        ];

        if ($card === null) {
            $attributes['source_id'] = $sourceId;
            $attributes['hero_profile_id'] = null;

            $card = Card::create($attributes);

            if ($cardTypeName === 'hero') {
                $this->seedHeroFor($card);
            }

            $this->syncClassifications($card, $types);
            $this->syncKeywords($card, $record);
            $this->syncLegality($card, $record);

            $this->created++;

            return;
        }

        $card->fill($attributes);
        $card->save();

        $this->syncClassifications($card, $types);
        $this->syncKeywords($card, $record);
        $this->syncLegality($card, $record);

        $this->updated++;
    }

    /**
     * Compute the deterministic source hash for a record.
     *
     * @param  array<string, mixed>  $record
     */
    private function sourceHashFor(array $record): string
    {
        return hash('sha256', json_encode([
            'unique_id' => $record['unique_id'] ?? null,
            'name' => $record['name'] ?? null,
            'pitch' => $record['pitch'] ?? null,
            'cost' => $record['cost'] ?? null,
            'power' => $record['power'] ?? null,
            'defense' => $record['defense'] ?? null,
            'types' => $record['types'] ?? [],
            'card_keywords' => $record['card_keywords'] ?? [],
            'functional_text_plain' => $record['functional_text_plain'] ?? null,
            'silver_age_legal' => $record['silver_age_legal'] ?? null,
            'silver_age_banned' => $record['silver_age_banned'] ?? null,
            'cc_legal' => $record['cc_legal'] ?? null,
            'cc_banned' => $record['cc_banned'] ?? null,
            'cc_suspended' => $record['cc_suspended'] ?? null,
            'blitz_legal' => $record['blitz_legal'] ?? null,
            'blitz_banned' => $record['blitz_banned'] ?? null,
            'blitz_suspended' => $record['blitz_suspended'] ?? null,
            'commoner_legal' => $record['commoner_legal'] ?? null,
            'commoner_banned' => $record['commoner_banned'] ?? null,
            'commoner_suspended' => $record['commoner_suspended'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Classify a record's raw types array into a card_types.name, flagging unrecognized types.
     *
     * @param  array<int, string>  $types
     * @param  array<string, mixed>  $record
     */
    private function classifyCardType(array $types, array $record): string
    {
        foreach (self::TYPE_CLASSIFICATION as [$tokens, $cardTypeName]) {
            foreach ($tokens as $token) {
                if (in_array($token, $types, true)) {
                    return $cardTypeName;
                }
            }
        }

        Log::warning('Unrecognized card type', [
            'source_id' => $record['unique_id'] ?? null,
            'name' => $record['name'] ?? null,
            'types' => $types,
        ]);
        $this->warn('Unrecognized card type for '.($record['name'] ?? 'unknown'));
        $this->flagged++;

        return 'other';
    }

    /**
     * Sync class and talent classifications for a card based on its raw types.
     *
     * @param  array<int, string>  $types
     */
    private function syncClassifications(Card $card, array $types): void
    {
        $classIds = [];
        foreach (self::CLASS_TOKENS as $token) {
            if (in_array($token, $types, true)) {
                $classIds[] = $this->classIdFor($token);
            }
        }

        $talentIds = [];
        foreach (self::TALENT_TOKENS as $token) {
            if (in_array($token, $types, true)) {
                $talentIds[] = $this->talentIdFor($token);
            }
        }

        $card->classes()->sync($classIds);
        $card->talents()->sync($talentIds);
    }

    /**
     * Sync a card's keywords based on the raw card_keywords field.
     *
     * @param  array<string, mixed>  $record
     */
    private function syncKeywords(Card $card, array $record): void
    {
        $rawKeywords = $record['card_keywords'] ?? [];
        $rawKeywords = is_array($rawKeywords) ? $rawKeywords : [];

        $keywordIds = [];
        foreach ($rawKeywords as $raw) {
            if (! is_string($raw)) {
                continue;
            }

            $base = $this->normalizeKeyword($raw);

            if ($base === '') {
                continue;
            }

            $keywordIds[] = $this->keywordIdFor($base);
        }

        $card->keywords()->sync(array_values(array_unique($keywordIds)));
    }

    /**
     * Sync a card's legality rows across the four supported formats.
     *
     * @param  array<string, mixed>  $record
     */
    private function syncLegality(Card $card, array $record): void
    {
        foreach (self::LEGALITY_FORMATS as $format => $flags) {
            $banned = (bool) ($record[$flags['banned']] ?? false);
            $suspended = $flags['suspended'] !== null && (bool) ($record[$flags['suspended']] ?? false);
            $legal = (bool) ($record[$flags['legal']] ?? false);

            $status = match (true) {
                $banned => 'banned',
                $suspended => 'suspended',
                $legal => 'legal',
                default => null,
            };

            if ($status === null) {
                CardLegality::where('card_id', $card->id)->where('format', $format)->delete();

                continue;
            }

            CardLegality::updateOrCreate(
                ['card_id' => $card->id, 'format' => $format],
                ['status' => $status],
            );
        }
    }

    /**
     * Seed a strictly 1:1:1 hero/hero_profile pair for a newly inserted hero card.
     */
    private function seedHeroFor(Card $card): void
    {
        $hero = Hero::create(['name' => $card->name]);

        $heroProfile = HeroProfile::create([
            'hero_id' => $hero->id,
            'label' => $card->name,
        ]);

        $card->hero_profile_id = $heroProfile->id;
        $card->save();
    }

    /**
     * Normalize a raw card_keywords entry to its base keyword name (strips a trailing count).
     */
    private function normalizeKeyword(string $raw): string
    {
        return trim(preg_replace('/\s+\d+$/', '', trim($raw)));
    }

    /**
     * Cast a nullable numeric string to a nullable int, returning null for non-numeric values.
     */
    private function toNullableInt(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $unsigned = str_starts_with($trimmed, '-') ? substr($trimmed, 1) : $trimmed;

        if ($unsigned === '' || ! ctype_digit($unsigned)) {
            return null;
        }

        return (int) $trimmed;
    }

    /**
     * Resolve (and cache) a class id for the given token.
     */
    private function classIdFor(string $name): int
    {
        if (! isset($this->classIds[$name])) {
            $this->classIds[$name] = CardClass::firstOrCreate(['name' => $name])->id;
        }

        return $this->classIds[$name];
    }

    /**
     * Resolve (and cache) a talent id for the given token.
     */
    private function talentIdFor(string $name): int
    {
        if (! isset($this->talentIds[$name])) {
            $this->talentIds[$name] = Talent::firstOrCreate(['name' => $name])->id;
        }

        return $this->talentIds[$name];
    }

    /**
     * Resolve (and cache) a keyword id for the given base keyword name.
     */
    private function keywordIdFor(string $name): int
    {
        if (! isset($this->keywordIds[$name])) {
            $this->keywordIds[$name] = Keyword::firstOrCreate(['name' => $name])->id;
        }

        return $this->keywordIds[$name];
    }
}
