<?php

namespace App\Services\Enrichment;

use App\Models\Card;
use App\Models\CardExplainer;
use RuntimeException;

/**
 * Renders skills/enrichment/explainer-validation/prompt.md and loads its
 * schema.json. Grounding sections that have nothing to say render the
 * literal `(none)` rather than an empty string, so the prompt always signals
 * explicitly when a section is empty.
 */
class ExplainerValidationPromptBuilder
{
    private const PROMPT_PATH = 'skills/enrichment/explainer-validation/prompt.md';

    private const SCHEMA_PATH = 'skills/enrichment/explainer-validation/schema.json';

    public function build(Card $card, CardExplainer $explainer): string
    {
        return strtr($this->loadPrompt(), [
            '{{functional_text}}' => $this->textOrNone($card->functional_text),
            '{{explainer_text}}' => $this->textOrNone($explainer->explainer_text),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $path = base_path(self::SCHEMA_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Explainer validation schema file not found: {$path}");
        }

        $schema = json_decode((string) file_get_contents($path), true);

        if (! is_array($schema)) {
            throw new RuntimeException("Explainer validation schema file could not be decoded: {$path}");
        }

        return $schema;
    }

    private function loadPrompt(): string
    {
        $path = base_path(self::PROMPT_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Explainer validation prompt template not found: {$path}");
        }

        return (string) file_get_contents($path);
    }

    private function textOrNone(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '(none)' : $value;
    }
}
