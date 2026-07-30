<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\ComboPair;
use App\Models\SynergyTag;
use App\Services\Enrichment\ComboSynergyPlausibilityAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComboSynergyPlausibilityAuditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['enrichment.embedding.dimensions' => 3]);
    }

    public function test_it_scores_a_draft_combo_pair_with_both_cards_present(): void
    {
        $cardA = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $cardB = Card::factory()->create(['functional_text' => 'Draw a card.']);

        $comboPair = ComboPair::factory()->create([
            'card_id_a' => $cardA->id,
            'card_id_b' => $cardB->id,
            'status' => 'draft',
        ]);

        Http::fake([
            '*' => Http::response(['data' => [
                ['index' => 0, 'embedding' => [1, 0, 0]],
                ['index' => 1, 'embedding' => [0, 1, 0]],
            ]]),
        ]);

        $count = app(ComboSynergyPlausibilityAuditor::class)->auditComboPairs();

        $this->assertSame(1, $count);
        $this->assertNotNull($comboPair->fresh()->plausibility_score);
    }

    public function test_it_skips_a_draft_combo_pair_with_empty_functional_text_and_makes_no_voyage_call(): void
    {
        Http::preventStrayRequests();

        $cardA = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $cardB = Card::factory()->create(['functional_text' => '']);

        $comboPair = ComboPair::factory()->create([
            'card_id_a' => $cardA->id,
            'card_id_b' => $cardB->id,
            'status' => 'draft',
        ]);

        $count = app(ComboSynergyPlausibilityAuditor::class)->auditComboPairs();

        $this->assertSame(0, $count);
        $this->assertNull($comboPair->fresh()->plausibility_score);
        Http::assertNothingSent();
    }

    public function test_it_scores_a_draft_card_synergy_tag_row(): void
    {
        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $tag = SynergyTag::factory()->create(['description' => 'Direct damage synergy.']);

        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $tag->id,
            'status' => 'draft',
        ]);

        Http::fake([
            '*' => Http::response(['data' => [
                ['index' => 0, 'embedding' => [1, 0, 0]],
                ['index' => 1, 'embedding' => [0, 1, 0]],
            ]]),
        ]);

        $count = app(ComboSynergyPlausibilityAuditor::class)->auditSynergyTags();

        $this->assertSame(1, $count);

        $row = DB::table('card_synergy_tags')
            ->where('card_id', $card->id)
            ->where('synergy_tag_id', $tag->id)
            ->first();

        $this->assertNotNull($row->plausibility_score);
    }

    public function test_it_skips_a_card_synergy_tag_row_with_empty_description_and_makes_no_voyage_call(): void
    {
        Http::preventStrayRequests();

        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $tag = SynergyTag::factory()->create(['description' => '']);

        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $tag->id,
            'status' => 'draft',
        ]);

        $count = app(ComboSynergyPlausibilityAuditor::class)->auditSynergyTags();

        $this->assertSame(0, $count);

        $row = DB::table('card_synergy_tags')
            ->where('card_id', $card->id)
            ->where('synergy_tag_id', $tag->id)
            ->first();

        $this->assertNull($row->plausibility_score);
        Http::assertNothingSent();
    }

    public function test_it_returns_zero_and_makes_no_voyage_call_when_there_are_no_draft_rows(): void
    {
        Http::preventStrayRequests();

        $auditor = app(ComboSynergyPlausibilityAuditor::class);

        $this->assertSame(0, $auditor->auditComboPairs());
        $this->assertSame(0, $auditor->auditSynergyTags());
        Http::assertNothingSent();
    }

    public function test_it_does_not_score_non_draft_rows(): void
    {
        Http::preventStrayRequests();

        $cardA = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $cardB = Card::factory()->create(['functional_text' => 'Draw a card.']);

        $comboPair = ComboPair::factory()->create([
            'card_id_a' => $cardA->id,
            'card_id_b' => $cardB->id,
            'status' => 'validated',
        ]);

        $card = Card::factory()->create(['functional_text' => 'Deal 1 damage.']);
        $tag = SynergyTag::factory()->create(['description' => 'Direct damage synergy.']);

        DB::table('card_synergy_tags')->insert([
            'card_id' => $card->id,
            'synergy_tag_id' => $tag->id,
            'status' => 'validated',
        ]);

        $auditor = app(ComboSynergyPlausibilityAuditor::class);

        $this->assertSame(0, $auditor->auditComboPairs());
        $this->assertSame(0, $auditor->auditSynergyTags());

        $this->assertNull($comboPair->fresh()->plausibility_score);

        $row = DB::table('card_synergy_tags')
            ->where('card_id', $card->id)
            ->where('synergy_tag_id', $tag->id)
            ->first();

        $this->assertNull($row->plausibility_score);
        Http::assertNothingSent();
    }
}
