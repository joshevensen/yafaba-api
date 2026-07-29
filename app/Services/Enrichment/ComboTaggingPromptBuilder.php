<?php

namespace App\Services\Enrichment;

use App\Models\Card;
use App\Models\KbDocument;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Renders skills/enrichment/combo-tagging/prompt.md and loads its
 * schema.json. Grounding sections that have nothing to say render the
 * literal `(none)` rather than an empty string, so the prompt always signals
 * explicitly when a section is empty.
 */
class ComboTaggingPromptBuilder
{
    private const PROMPT_PATH = 'skills/enrichment/combo-tagging/prompt.md';

    private const SCHEMA_PATH = 'skills/enrichment/combo-tagging/schema.json';

    /**
     * @param  Collection<int, Card>  $candidates
     * @param  Collection<int, KbDocument>  $validatedComboChunks
     */
    public function build(Card $card, Collection $candidates, Collection $validatedComboChunks): string
    {
        return strtr($this->loadPrompt(), [
            '{{card_name}}' => $card->name,
            '{{card_type}}' => $card->cardType?->name ?? '(unknown)',
            '{{functional_text}}' => $this->textOrNone($card->functional_text),
            '{{candidate_cards}}' => $this->formatCandidates($candidates),
            '{{validated_combo_examples}}' => $this->formatValidatedExamples($validatedComboChunks),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $path = base_path(self::SCHEMA_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Combo tagging schema file not found: {$path}");
        }

        $schema = json_decode((string) file_get_contents($path), true);

        if (! is_array($schema)) {
            throw new RuntimeException("Combo tagging schema file could not be decoded: {$path}");
        }

        return $schema;
    }

    private function loadPrompt(): string
    {
        $path = base_path(self::PROMPT_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Combo tagging prompt template not found: {$path}");
        }

        return (string) file_get_contents($path);
    }

    private function textOrNone(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '(none)' : $value;
    }

    /**
     * @param  Collection<int, Card>  $candidates
     */
    private function formatCandidates(Collection $candidates): string
    {
        if ($candidates->isEmpty()) {
            return '(none)';
        }

        return $candidates
            ->map(function (Card $candidate): string {
                $functionalText = trim((string) $candidate->functional_text);
                $functionalText = $functionalText === '' ? '(no functional text)' : $functionalText;

                return "- [{$candidate->id}] {$candidate->name}: {$functionalText}";
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
