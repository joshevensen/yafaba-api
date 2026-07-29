<?php

namespace App\Services\Enrichment;

use App\Models\Card;
use App\Models\ErrataBulletin;
use App\Models\KbDocument;
use App\Models\Keyword;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Renders skills/enrichment/card-explainer/prompt.md and loads its
 * schema.json. Grounding sections that have nothing to say render the
 * literal `(none)` rather than an empty string, so the prompt always signals
 * explicitly when a section is empty.
 */
class CardExplainerPromptBuilder
{
    private const PROMPT_PATH = 'skills/enrichment/card-explainer/prompt.md';

    private const SCHEMA_PATH = 'skills/enrichment/card-explainer/schema.json';

    /**
     * @param  Collection<int, KbDocument>  $kbChunks
     * @param  Collection<int, ErrataBulletin>  $errataBulletins
     */
    public function build(Card $card, Collection $kbChunks, Collection $errataBulletins): string
    {
        return strtr($this->loadPrompt(), [
            '{{card_name}}' => $card->name,
            '{{card_type}}' => $card->cardType?->name ?? '(unknown)',
            '{{functional_text}}' => $this->textOrNone($card->functional_text),
            '{{keyword_glossary}}' => $this->formatKeywords($card->keywords),
            '{{kb_chunks}}' => $this->formatKbChunks($kbChunks),
            '{{errata}}' => $this->formatErrata($errataBulletins),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $path = base_path(self::SCHEMA_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Card explainer schema file not found: {$path}");
        }

        $schema = json_decode((string) file_get_contents($path), true);

        if (! is_array($schema)) {
            throw new RuntimeException("Card explainer schema file could not be decoded: {$path}");
        }

        return $schema;
    }

    private function loadPrompt(): string
    {
        $path = base_path(self::PROMPT_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Card explainer prompt template not found: {$path}");
        }

        return (string) file_get_contents($path);
    }

    private function textOrNone(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '(none)' : $value;
    }

    /**
     * @param  Collection<int, Keyword>  $keywords
     */
    private function formatKeywords(Collection $keywords): string
    {
        $lines = $keywords
            ->map(function (Keyword $keyword): ?string {
                $text = $keyword->explainer ?? $keyword->rules_text;

                return $text === null ? null : "- {$keyword->name}: {$text}";
            })
            ->filter()
            ->values();

        return $lines->isEmpty() ? '(none)' : $lines->implode("\n");
    }

    /**
     * @param  Collection<int, KbDocument>  $chunks
     */
    private function formatKbChunks(Collection $chunks): string
    {
        if ($chunks->isEmpty()) {
            return '(none)';
        }

        return $chunks
            ->map(fn (KbDocument $chunk): string => "[{$chunk->source_ref}] {$chunk->content}")
            ->implode("\n");
    }

    /**
     * @param  Collection<int, ErrataBulletin>  $bulletins
     */
    private function formatErrata(Collection $bulletins): string
    {
        if ($bulletins->isEmpty()) {
            return '(none)';
        }

        return $bulletins
            ->map(fn (ErrataBulletin $bulletin): string => "[{$bulletin->bulletin_number}] {$bulletin->content}")
            ->implode("\n");
    }
}
