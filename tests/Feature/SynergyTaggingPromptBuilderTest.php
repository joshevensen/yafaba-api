<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\KbDocument;
use App\Models\SynergyTag;
use App\Services\Enrichment\SynergyTaggingPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SynergyTaggingPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_package_files_exist_and_schema_decodes(): void
    {
        $this->assertFileExists(base_path('skills/enrichment/synergy-tagging/SKILL.md'));
        $this->assertFileExists(base_path('skills/enrichment/synergy-tagging/prompt.md'));
        $this->assertFileExists(base_path('skills/enrichment/synergy-tagging/schema.json'));

        $schema = app(SynergyTaggingPromptBuilder::class)->schema();

        $this->assertSame(['tags', 'new_tags'], $schema['required']);
    }

    public function test_build_substitutes_every_placeholder(): void
    {
        $card = Card::factory()->create(['name' => 'Rhinar, Reckless Rampage', 'functional_text' => 'Whenever this hits a hero, gain +1 counter.']);
        $tag = SynergyTag::factory()->create(['name' => 'go-again-enabler', 'description' => 'Cards that grant go again.']);
        $chunk = KbDocument::factory()->create(['content' => 'This card synergizes with counter-generating attack actions.']);

        $prompt = app(SynergyTaggingPromptBuilder::class)->build($card, new Collection([$tag]), new Collection([$chunk]));

        $this->assertStringContainsString('Rhinar, Reckless Rampage', $prompt);
        $this->assertStringContainsString('Whenever this hits a hero, gain +1 counter.', $prompt);
        $this->assertStringContainsString((string) $tag->id, $prompt);
        $this->assertStringContainsString('go-again-enabler', $prompt);
        $this->assertStringContainsString('Cards that grant go again.', $prompt);
        $this->assertStringContainsString('This card synergizes with counter-generating attack actions.', $prompt);
        $this->assertStringNotContainsString('{{', $prompt);
    }

    public function test_build_renders_none_for_empty_grounding_inputs(): void
    {
        $card = Card::factory()->create(['functional_text' => null]);

        $prompt = app(SynergyTaggingPromptBuilder::class)->build($card, new Collection, new Collection);

        $this->assertStringNotContainsString('{{', $prompt);
        $this->assertSame(3, substr_count($prompt, '(none)'));
    }

    public function test_skill_version_matches_the_configured_prompt_version(): void
    {
        $contents = (string) file_get_contents(base_path('skills/enrichment/synergy-tagging/SKILL.md'));

        $this->assertMatchesRegularExpression('/^---\n(.*?)\n---/s', $contents);
        preg_match('/^---\n(.*?)\n---/s', $contents, $matches);

        $frontMatter = Yaml::parse($matches[1]);

        $this->assertSame(config('enrichment.prompt_version'), $frontMatter['version']);
    }
}
