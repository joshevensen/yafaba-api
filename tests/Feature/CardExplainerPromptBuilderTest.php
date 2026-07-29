<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\ErrataBulletin;
use App\Models\KbDocument;
use App\Models\Keyword;
use App\Services\Enrichment\CardExplainerPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CardExplainerPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_package_files_exist_and_schema_decodes(): void
    {
        $this->assertFileExists(base_path('skills/enrichment/card-explainer/SKILL.md'));
        $this->assertFileExists(base_path('skills/enrichment/card-explainer/prompt.md'));
        $this->assertFileExists(base_path('skills/enrichment/card-explainer/schema.json'));

        $schema = app(CardExplainerPromptBuilder::class)->schema();

        $this->assertSame(['explainer_text', 'cited_rules'], $schema['required']);
    }

    public function test_build_substitutes_every_placeholder(): void
    {
        $card = Card::factory()->create(['name' => 'Rhinar, Reckless Rampage', 'functional_text' => 'Whenever this hits a hero, gain +1 counter.']);
        $keyword = Keyword::factory()->create(['name' => 'Dominate', 'explainer' => 'Dominate glossary text.']);
        $card->keywords()->attach($keyword->id);
        $chunk = KbDocument::factory()->create(['source_ref' => '8.5.3b', 'content' => 'Dominate rule content.']);
        $bulletin = ErrataBulletin::factory()->create(['bulletin_number' => 'B-42', 'content' => 'Errata content for this card.']);

        $prompt = app(CardExplainerPromptBuilder::class)->build($card, new Collection([$chunk]), new Collection([$bulletin]));

        $this->assertStringContainsString('Rhinar, Reckless Rampage', $prompt);
        $this->assertStringContainsString('Whenever this hits a hero, gain +1 counter.', $prompt);
        $this->assertStringContainsString('Dominate glossary text.', $prompt);
        $this->assertStringContainsString('8.5.3b', $prompt);
        $this->assertStringContainsString('Dominate rule content.', $prompt);
        $this->assertStringContainsString('B-42', $prompt);
        $this->assertStringContainsString('Errata content for this card.', $prompt);
        $this->assertStringNotContainsString('{{', $prompt);
    }

    public function test_build_renders_none_for_empty_grounding_inputs(): void
    {
        $card = Card::factory()->create(['functional_text' => null]);

        $prompt = app(CardExplainerPromptBuilder::class)->build($card, new Collection, new Collection);

        $this->assertStringNotContainsString('{{', $prompt);
        $this->assertSame(4, substr_count($prompt, '(none)'));
    }
}
