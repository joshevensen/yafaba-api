<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\KbDocument;
use App\Services\Enrichment\ComboTaggingPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ComboTaggingPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_package_files_exist_and_schema_decodes(): void
    {
        $this->assertFileExists(base_path('skills/enrichment/combo-tagging/SKILL.md'));
        $this->assertFileExists(base_path('skills/enrichment/combo-tagging/prompt.md'));
        $this->assertFileExists(base_path('skills/enrichment/combo-tagging/schema.json'));

        $schema = app(ComboTaggingPromptBuilder::class)->schema();

        $this->assertSame(['combos'], $schema['required']);
    }

    public function test_build_substitutes_every_placeholder(): void
    {
        $card = Card::factory()->create(['name' => 'Rhinar, Reckless Rampage', 'functional_text' => 'Whenever this hits a hero, gain +1 counter.']);
        $candidateOne = Card::factory()->create(['name' => 'Snatch', 'functional_text' => 'Go again.']);
        $candidateTwo = Card::factory()->create(['name' => 'Enrage', 'functional_text' => 'Whenever this attacks, gain +1 counter.']);
        $chunk = KbDocument::factory()->create(['content' => 'Rhinar combos with counter-generating attack actions.']);

        $prompt = app(ComboTaggingPromptBuilder::class)->build($card, new Collection([$candidateOne, $candidateTwo]), new Collection([$chunk]));

        $this->assertStringContainsString('Rhinar, Reckless Rampage', $prompt);
        $this->assertStringContainsString('Whenever this hits a hero, gain +1 counter.', $prompt);
        $this->assertStringContainsString((string) $candidateOne->id, $prompt);
        $this->assertStringContainsString('Snatch', $prompt);
        $this->assertStringContainsString('Go again.', $prompt);
        $this->assertStringContainsString((string) $candidateTwo->id, $prompt);
        $this->assertStringContainsString('Enrage', $prompt);
        $this->assertStringContainsString('Whenever this attacks, gain +1 counter.', $prompt);
        $this->assertStringContainsString('Rhinar combos with counter-generating attack actions.', $prompt);
        $this->assertStringNotContainsString('{{', $prompt);
    }

    public function test_build_renders_none_for_empty_grounding_inputs(): void
    {
        $card = Card::factory()->create(['functional_text' => null]);

        $prompt = app(ComboTaggingPromptBuilder::class)->build($card, new Collection, new Collection);

        $this->assertStringNotContainsString('{{', $prompt);
        $this->assertSame(3, substr_count($prompt, '(none)'));
    }
}
