<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CardExplainer;
use App\Services\Enrichment\ExplainerValidationPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplainerValidationPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_package_files_exist_and_schema_decodes(): void
    {
        $this->assertFileExists(base_path('skills/enrichment/explainer-validation/SKILL.md'));
        $this->assertFileExists(base_path('skills/enrichment/explainer-validation/prompt.md'));
        $this->assertFileExists(base_path('skills/enrichment/explainer-validation/schema.json'));

        $schema = app(ExplainerValidationPromptBuilder::class)->schema();

        $this->assertSame(['is_consistent', 'confidence', 'reason'], $schema['required']);
    }

    public function test_build_substitutes_every_placeholder(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Whenever this hits a hero, gain +1 counter.']);
        $explainer = CardExplainer::factory()->create(['explainer_text' => 'This card gains a counter when it hits a hero.']);

        $prompt = app(ExplainerValidationPromptBuilder::class)->build($card, $explainer);

        $this->assertStringContainsString('Whenever this hits a hero, gain +1 counter.', $prompt);
        $this->assertStringContainsString('This card gains a counter when it hits a hero.', $prompt);
        $this->assertStringNotContainsString('{{', $prompt);
    }

    public function test_build_renders_none_for_empty_grounding_inputs(): void
    {
        $card = Card::factory()->create(['functional_text' => null]);
        $explainer = CardExplainer::factory()->create(['explainer_text' => 'Some explainer text.']);

        $prompt = app(ExplainerValidationPromptBuilder::class)->build($card, $explainer);

        $this->assertStringNotContainsString('{{', $prompt);
        $this->assertStringContainsString('(none)', $prompt);
    }
}
