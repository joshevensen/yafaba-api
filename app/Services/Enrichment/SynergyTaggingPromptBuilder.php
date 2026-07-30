<?php

namespace App\Services\Enrichment;

use App\Models\Card;
use App\Models\KbDocument;
use App\Models\SynergyTag;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Renders skills/enrichment/synergy-tagging/prompt.md and loads its
 * schema.json. Grounding sections that have nothing to say render the
 * literal `(none)` rather than an empty string, so the prompt always signals
 * explicitly when a section is empty.
 */
class SynergyTaggingPromptBuilder
{
    private const PROMPT_PATH = 'skills/enrichment/synergy-tagging/prompt.md';

    private const SCHEMA_PATH = 'skills/enrichment/synergy-tagging/schema.json';

    /**
     * @param  Collection<int, SynergyTag>  $vocabulary
     * @param  Collection<int, KbDocument>  $validatedSynergyChunks
     */
    public function build(Card $card, Collection $vocabulary, Collection $validatedSynergyChunks): string
    {
        return strtr($this->loadPrompt(), [
            '{{card_name}}' => $card->name,
            '{{card_type}}' => $card->cardType?->name ?? '(unknown)',
            '{{functional_text}}' => $this->textOrNone($card->functional_text),
            '{{synergy_tag_vocabulary}}' => $this->formatVocabulary($vocabulary),
            '{{validated_synergy_examples}}' => $this->formatValidatedExamples($validatedSynergyChunks),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $path = base_path(self::SCHEMA_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Synergy tagging schema file not found: {$path}");
        }

        $schema = json_decode((string) file_get_contents($path), true);

        if (! is_array($schema)) {
            throw new RuntimeException("Synergy tagging schema file could not be decoded: {$path}");
        }

        return $schema;
    }

    private function loadPrompt(): string
    {
        $path = base_path(self::PROMPT_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Synergy tagging prompt template not found: {$path}");
        }

        return (string) file_get_contents($path);
    }

    private function textOrNone(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '(none)' : $value;
    }

    /**
     * @param  Collection<int, SynergyTag>  $tags
     */
    private function formatVocabulary(Collection $tags): string
    {
        if ($tags->isEmpty()) {
            return '(none)';
        }

        return $tags
            ->map(function (SynergyTag $tag): string {
                $description = trim((string) $tag->description);
                $description = $description === '' ? '(no description)' : $description;

                return "- [{$tag->id}] {$tag->name}: {$description}";
            })
            ->implode("\n");
    }

    /**
     * @param  Collection<int, KbDocument>  $chunks
     */
    private function formatValidatedExamples(Collection $chunks): string
    {
        if ($chunks->isEmpty()) {
            return '(none)';
        }

        return $chunks
            ->map(fn (KbDocument $chunk): string => "- {$chunk->content}")
            ->implode("\n");
    }
}
