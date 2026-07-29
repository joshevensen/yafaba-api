<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CardExplainer;
use App\Models\ComboPair;
use App\Models\ErrataBulletin;
use App\Models\KbDocument;
use App\Models\RulesTextVersion;
use App\Models\SynergyTag;
use App\Services\Enrichment\KbChunker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KbChunkerTest extends TestCase
{
    use RefreshDatabase;

    private function chunker(): KbChunker
    {
        return app(KbChunker::class);
    }

    public function test_rules_text_splits_on_section_regex_and_preserves_preamble(): void
    {
        config(['enrichment.embedding.min_chunk_chars' => 10, 'enrichment.embedding.max_chunk_chars' => 10000]);

        $text = implode("\n", [
            'Preamble line one describing the game in general terms for players everywhere.',
            'Preamble line two continuing the general description at some length here too.',
            '1.0 Section one covers timing and priority rules in extensive detail for readers.',
            'Continuation of section one with more detail to push the content well past two hundred characters in total length for this section body specifically.',
            '1.1 Section two covers stack resolution order and follows on from section one nicely.',
            'Continuation of section two with more detail to push the content well past two hundred characters in total length for this section body specifically too.',
            '2.0 Section three covers combat and damage assignment rules in extensive detail here.',
            'Continuation of section three with more detail to push the content well past two hundred characters in total length for this section body specifically as well.',
        ]);

        $version = RulesTextVersion::factory()->create(['full_text' => $text, 'fetched_at' => now()]);

        $chunks = $this->chunker()->chunkRulesText($version);

        $this->assertCount(4, $chunks);
        $this->assertSame(['preamble', '1.0', '1.1', '2.0'], array_column($chunks, 'source_ref'));

        foreach ($chunks as $chunk) {
            $this->assertSame(KbDocument::SOURCE_CR_RULES, $chunk['source_type']);
            $this->assertSame(KbDocument::TRUST_GROUND_TRUTH, $chunk['trust_status']);
            $this->assertSame($version->fetched_at->toDateString(), $chunk['effective_date']);
        }
    }

    public function test_small_sections_merge_into_the_following_section(): void
    {
        config(['enrichment.embedding.min_chunk_chars' => 200, 'enrichment.embedding.max_chunk_chars' => 10000]);

        $text = implode("\n", [
            '1.0 Tiny.',
            '1.1 Section two covers stack resolution order and follows on from section one nicely, with enough extra detail here to clear the two hundred character merge threshold comfortably on its own and then some.',
        ]);

        $version = RulesTextVersion::factory()->create(['full_text' => $text]);

        $chunks = $this->chunker()->chunkRulesText($version);

        $this->assertCount(1, $chunks);
        $this->assertSame('1.1', $chunks[0]['source_ref']);
        $this->assertStringContainsString('1.0 Tiny.', $chunks[0]['content']);
        $this->assertStringContainsString('Section two covers', $chunks[0]['content']);
    }

    public function test_oversized_section_is_split_with_indexed_source_refs(): void
    {
        config(['enrichment.embedding.min_chunk_chars' => 0, 'enrichment.embedding.max_chunk_chars' => 50]);

        $version = RulesTextVersion::factory()->create(['full_text' => '1.0 '.str_repeat('x', 150)]);

        $chunks = $this->chunker()->chunkRulesText($version);

        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame('1.0#1', $chunks[0]['source_ref']);
        $this->assertSame('1.0#2', $chunks[1]['source_ref']);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(50, mb_strlen($chunk['content']));
        }
    }

    public function test_errata_chunk_contains_no_html_tags(): void
    {
        $bulletin = ErrataBulletin::factory()->create(['content' => '<p>Card X now reads &quot;draw a card&quot;.</p>']);

        $chunks = $this->chunker()->chunkErrataBulletin($bulletin);

        $this->assertCount(1, $chunks);
        $this->assertStringNotContainsString('<p>', $chunks[0]['content']);
        $this->assertStringContainsString('Card X now reads "draw a card".', $chunks[0]['content']);
        $this->assertSame(KbDocument::SOURCE_ERRATA, $chunks[0]['source_type']);
        $this->assertSame(KbDocument::TRUST_GROUND_TRUTH, $chunks[0]['trust_status']);
        $this->assertSame($bulletin->id, $chunks[0]['source_ref']);
    }

    public function test_validated_notes_are_included_and_draft_notes_are_excluded(): void
    {
        $validatedCard = Card::factory()->create();
        $draftCard = Card::factory()->create();

        CardExplainer::factory()->create(['card_id' => $validatedCard->id, 'status' => 'validated', 'explainer_text' => 'Validated explainer text.']);
        CardExplainer::factory()->create(['card_id' => $draftCard->id, 'status' => 'draft', 'explainer_text' => 'Draft explainer text.']);

        $comboA = Card::factory()->create();
        $comboB = Card::factory()->create();
        $comboC = Card::factory()->create();
        ComboPair::factory()->create(['card_id_a' => $comboA->id, 'card_id_b' => $comboB->id, 'status' => 'validated', 'description' => 'Validated combo text.']);
        ComboPair::factory()->create(['card_id_a' => $comboA->id, 'card_id_b' => $comboC->id, 'status' => 'draft', 'description' => 'Draft combo text.']);

        $synergyCard = Card::factory()->create();
        $tag = SynergyTag::factory()->create(['name' => 'Go Again', 'description' => 'Cards that grant additional actions.']);
        DB::table('card_synergy_tags')->insert(['card_id' => $synergyCard->id, 'synergy_tag_id' => $tag->id, 'status' => 'validated']);

        $draftSynergyCard = Card::factory()->create();
        DB::table('card_synergy_tags')->insert(['card_id' => $draftSynergyCard->id, 'synergy_tag_id' => $tag->id, 'status' => 'draft']);

        $chunks = $this->chunker()->chunkValidatedNotes();

        $contents = array_column($chunks, 'content');
        $this->assertContains('Validated explainer text.', $contents);
        $this->assertNotContains('Draft explainer text.', $contents);
        $this->assertContains('Validated combo text.', $contents);
        $this->assertNotContains('Draft combo text.', $contents);

        $synergyChunk = collect($chunks)->firstWhere('source_type', KbDocument::SOURCE_SYNERGY);
        $this->assertNotNull($synergyChunk);
        $this->assertSame("{$synergyCard->id}:{$tag->id}", $synergyChunk['source_ref']);
        $this->assertStringContainsString('Go Again', $synergyChunk['content']);

        foreach ($chunks as $chunk) {
            $this->assertSame(KbDocument::TRUST_VALIDATED, $chunk['trust_status']);
        }
    }
}
