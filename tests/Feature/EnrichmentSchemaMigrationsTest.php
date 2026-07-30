<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CardExplainer;
use App\Models\ComboPair;
use App\Models\KbDocument;
use App\Models\SynergyTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrichmentSchemaMigrationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<int, string>>
     */
    private function expectedColumns(): array
    {
        return [
            'card_explainers' => ['card_id', 'explainer_text', 'cited_rules', 'status', 'generated_at', 'validated_at', 'source_hash', 'prompt_version', 'grounding_confidence', 'grounding_flag_reason'],
            'combo_pairs' => ['id', 'card_id_a', 'card_id_b', 'description', 'status', 'self_play_win_rate_delta', 'source_hash', 'prompt_version', 'generated_at', 'plausibility_score', 'human_signoff_at'],
            'synergy_tags' => ['id', 'name', 'description', 'status'],
            'card_synergy_tags' => ['card_id', 'synergy_tag_id', 'status', 'source_hash', 'prompt_version', 'generated_at', 'plausibility_score', 'human_signoff_at'],
            'kb_documents' => ['id', 'source_type', 'source_ref', 'content', 'embedding', 'embedding_model', 'trust_status', 'version', 'effective_date', 'created_at'],
        ];
    }

    /**
     * @return array<string, array<int, array{columns: array<int, string>, foreign_table: string}>>
     */
    private function expectedForeignKeys(): array
    {
        return [
            'card_explainers' => [
                ['columns' => ['card_id'], 'foreign_table' => 'cards'],
            ],
            'combo_pairs' => [
                ['columns' => ['card_id_a'], 'foreign_table' => 'cards'],
                ['columns' => ['card_id_b'], 'foreign_table' => 'cards'],
            ],
            'card_synergy_tags' => [
                ['columns' => ['card_id'], 'foreign_table' => 'cards'],
                ['columns' => ['synergy_tag_id'], 'foreign_table' => 'synergy_tags'],
            ],
        ];
    }

    public function test_every_enrichment_table_exists(): void
    {
        foreach (array_keys($this->expectedColumns()) as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table [{$table}] to exist.");
        }
    }

    public function test_every_table_has_exactly_the_expected_columns(): void
    {
        foreach ($this->expectedColumns() as $table => $columns) {
            $this->assertTrue(
                Schema::hasColumns($table, $columns),
                "Table [{$table}] is missing one or more expected columns."
            );

            $actual = collect(Schema::getColumnListing($table))->sort()->values()->all();
            $expected = collect($columns)->sort()->values()->all();

            $this->assertSame(
                $expected,
                $actual,
                "Table [{$table}] has unexpected columns. Expected: ".implode(', ', $expected).' — Got: '.implode(', ', $actual)
            );
        }
    }

    public function test_foreign_keys_point_at_the_expected_parent_tables(): void
    {
        foreach ($this->expectedForeignKeys() as $table => $expectedKeys) {
            $actualKeys = Schema::getForeignKeys($table);

            foreach ($expectedKeys as $expected) {
                $match = collect($actualKeys)->first(
                    fn (array $fk) => $fk['columns'] === $expected['columns'] && $fk['foreign_table'] === $expected['foreign_table']
                );

                $this->assertNotNull(
                    $match,
                    "Table [{$table}] is missing a foreign key on [".implode(',', $expected['columns'])."] -> [{$expected['foreign_table']}]."
                );
            }
        }
    }

    public function test_card_explainers_primary_key_is_card_id(): void
    {
        $indexes = Schema::getIndexes('card_explainers');

        $primary = collect($indexes)->first(
            fn (array $index) => $index['primary'] && $index['columns'] === ['card_id']
        );

        $this->assertNotNull($primary, 'card_explainers is missing a primary key over [card_id].');
    }

    public function test_card_synergy_tags_has_a_composite_primary_key(): void
    {
        $indexes = Schema::getIndexes('card_synergy_tags');

        $primary = collect($indexes)->first(
            fn (array $index) => $index['primary'] && $index['columns'] === ['card_id', 'synergy_tag_id']
        );

        $this->assertNotNull($primary, 'card_synergy_tags is missing a composite primary key over [card_id, synergy_tag_id].');
    }

    public function test_expected_unique_indexes_exist(): void
    {
        $synergyTagIndexes = Schema::getIndexes('synergy_tags');

        $uniqueName = collect($synergyTagIndexes)->first(
            fn (array $index) => $index['unique'] && $index['columns'] === ['name']
        );

        $this->assertNotNull($uniqueName, 'synergy_tags is missing a unique index over [name].');

        $comboPairIndexes = Schema::getIndexes('combo_pairs');

        $uniquePair = collect($comboPairIndexes)->first(
            fn (array $index) => $index['unique'] && $index['columns'] === ['card_id_a', 'card_id_b']
        );

        $this->assertNotNull($uniquePair, 'combo_pairs is missing a unique index over [card_id_a, card_id_b].');
    }

    public function test_status_columns_default_to_draft(): void
    {
        $card = Card::factory()->create();

        DB::table('card_explainers')->insert(['card_id' => $card->id]);
        $this->assertSame('draft', DB::table('card_explainers')->where('card_id', $card->id)->value('status'));

        $comboId = (string) Str::uuid();
        $partner = Card::factory()->create();
        DB::table('combo_pairs')->insert([
            'id' => $comboId,
            'card_id_a' => $card->id,
            'card_id_b' => $partner->id,
        ]);
        $this->assertSame('draft', DB::table('combo_pairs')->where('id', $comboId)->value('status'));

        $tagId = (string) Str::uuid();
        DB::table('synergy_tags')->insert(['id' => $tagId, 'name' => 'test_tag']);
        DB::table('card_synergy_tags')->insert(['card_id' => $card->id, 'synergy_tag_id' => $tagId]);
        $this->assertSame('draft', DB::table('card_synergy_tags')->where('card_id', $card->id)->where('synergy_tag_id', $tagId)->value('status'));

        $kbId = (string) Str::uuid();
        DB::table('kb_documents')->insert(['id' => $kbId, 'source_type' => 'cr_rules']);
        $this->assertSame('draft', DB::table('kb_documents')->where('id', $kbId)->value('trust_status'));
    }

    public function test_kb_document_embedding_round_trips_a_1024_dimension_vector(): void
    {
        $document = KbDocument::factory()->embedded()->create();

        $reloaded = $document->fresh();

        $this->assertNotNull($reloaded->embedding);

        $values = explode(',', trim($reloaded->embedding, '[]'));
        $this->assertCount(1024, $values);
    }

    public function test_migrations_roll_back_completely(): void
    {
        $this->artisan('migrate:reset')->assertExitCode(0);

        foreach (array_keys($this->expectedColumns()) as $table) {
            $this->assertFalse(Schema::hasTable($table), "Expected table [{$table}] to be dropped after rollback.");
        }

        // Restore the schema so RefreshDatabase can tear down cleanly for other tests.
        $this->artisan('migrate')->assertExitCode(0);
    }

    public function test_every_new_model_persists_through_its_factory(): void
    {
        $explainer = CardExplainer::factory()->create();
        $this->assertDatabaseHas('card_explainers', ['card_id' => $explainer->card_id]);

        $combo = ComboPair::factory()->create();
        $this->assertDatabaseHas('combo_pairs', ['id' => $combo->id]);

        $tag = SynergyTag::factory()->create();
        $this->assertDatabaseHas('synergy_tags', ['id' => $tag->id]);

        $document = KbDocument::factory()->create();
        $this->assertDatabaseHas('kb_documents', ['id' => $document->id]);
    }

    public function test_card_relationships_resolve(): void
    {
        $card = Card::factory()->create();

        $explainer = CardExplainer::factory()->create(['card_id' => $card->id]);
        $this->assertTrue($card->explainer->is($explainer));

        $partner = Card::factory()->create();
        $comboAsA = ComboPair::factory()->create(['card_id_a' => $card->id, 'card_id_b' => $partner->id]);
        $this->assertTrue($card->comboPairs->contains($comboAsA));

        $comboAsB = ComboPair::factory()->create(['card_id_a' => $partner->id, 'card_id_b' => $card->id]);
        $this->assertTrue($card->comboPairsAsPartner->contains($comboAsB));

        $tag = SynergyTag::factory()->create();
        $card->synergyTags()->attach($tag->id, ['status' => 'draft']);
        $this->assertTrue($card->synergyTags->contains($tag));
        $this->assertSame('draft', $card->synergyTags->first()->pivot->status);
    }
}
