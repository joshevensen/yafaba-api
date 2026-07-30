<?php

namespace Tests\Feature;

use App\Models\CardExplainer;
use App\Models\RulesTextVersion;
use App\Services\Enrichment\ExplainerGroundingChecker;
use App\Services\Enrichment\KbChunker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplainerGroundingCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cited_rule_matching_a_parsed_section_key_is_grounded(): void
    {
        RulesTextVersion::factory()->create([
            'full_text' => "1.0 Preamble text.\n1.1 Rules of the game.\n8.5.3b Some subrule about copies of a card.",
            'fetched_at' => now(),
        ]);

        $explainer = CardExplainer::factory()->create(['cited_rules' => ['8.5.3b']]);

        $unknown = app(ExplainerGroundingChecker::class)->unknownCitedRules($explainer);

        $this->assertSame([], $unknown);
    }

    public function test_a_cited_rule_absent_from_the_snapshot_is_reported_unknown(): void
    {
        RulesTextVersion::factory()->create([
            'full_text' => "1.0 Preamble text.\n1.1 Rules of the game.\n8.5.3b Some subrule about copies of a card.",
            'fetched_at' => now(),
        ]);

        $explainer = CardExplainer::factory()->create(['cited_rules' => ['9.9.9z']]);

        $unknown = app(ExplainerGroundingChecker::class)->unknownCitedRules($explainer);

        $this->assertSame(['9.9.9z'], $unknown);
    }

    public function test_a_cited_rule_stays_valid_regardless_of_the_embedding_chunk_size_config(): void
    {
        // The grounding check reads raw parsed sections (KbChunker::parseSections),
        // not the merged/split embedding chunks, so a section that KbChunker's
        // embedding pipeline would split into "8.5.3b#1", "8.5.3b#2", etc. under a
        // small max_chunk_chars must still validate against its bare rule number.
        config(['enrichment.embedding.max_chunk_chars' => 50]);

        $longBody = '8.5.3b '.str_repeat('A player may not have more than four copies of a card. ', 5);

        RulesTextVersion::factory()->create([
            'full_text' => "1.0 Preamble text.\n{$longBody}",
            'fetched_at' => now(),
        ]);

        $explainer = CardExplainer::factory()->create(['cited_rules' => ['8.5.3b']]);

        $unknown = app(ExplainerGroundingChecker::class)->unknownCitedRules($explainer);

        $this->assertSame([], $unknown);
    }

    public function test_every_cited_rule_is_unknown_when_there_is_no_rules_text_version(): void
    {
        $explainer = CardExplainer::factory()->create(['cited_rules' => ['1.1', '8.5.3b', '1.1']]);

        $unknown = app(ExplainerGroundingChecker::class)->unknownCitedRules($explainer);

        $this->assertSame(['1.1', '8.5.3b'], $unknown);
    }

    public function test_an_empty_cited_rules_array_returns_no_unknowns(): void
    {
        RulesTextVersion::factory()->create([
            'full_text' => "1.0 Preamble text.\n1.1 Rules of the game.",
            'fetched_at' => now(),
        ]);

        $explainer = CardExplainer::factory()->create(['cited_rules' => []]);

        $unknown = app(ExplainerGroundingChecker::class)->unknownCitedRules($explainer);

        $this->assertSame([], $unknown);
    }

    public function test_it_can_be_constructed_directly_with_a_kb_chunker(): void
    {
        $checker = new ExplainerGroundingChecker(new KbChunker);

        $explainer = CardExplainer::factory()->create(['cited_rules' => []]);

        $this->assertSame([], $checker->unknownCitedRules($explainer));
    }
}
