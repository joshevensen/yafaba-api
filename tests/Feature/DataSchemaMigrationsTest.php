<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DataSchemaMigrationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<int, string>>
     */
    private function expectedColumns(): array
    {
        return [
            'card_types' => ['id', 'name', 'description', 'display_order', 'notes'],
            'classes' => ['id', 'name', 'mechanical_theme', 'complexity_pattern', 'resource_lean', 'description', 'notes'],
            'talents' => ['id', 'name', 'description', 'notes'],
            'keywords' => ['id', 'name', 'rules_text', 'explainer', 'cited_rules', 'notes'],
            'heroes' => ['id', 'name', 'lore', 'flavor', 'notes'],
            'hero_profiles' => ['id', 'hero_id', 'label', 'pattern_summary', 'complexity_score', 'complexity_rating', 'playstyle_tags', 'pitch_lean', 'notes'],
            'cards' => ['id', 'name', 'card_type_id', 'pitch_value', 'cost', 'power', 'defense', 'functional_text', 'hero_profile_id', 'age', 'hero_profile_match_confidence', 'hero_profile_grouping_status', 'source_hash', 'source_id', 'updated_at'],
            'card_printings' => ['id', 'card_id', 'set_code', 'rarity', 'finish', 'art_variant', 'image_url', 'cardvault_print_id', 'image_source_hash', 'price_cache', 'price_updated_at'],
            'card_legality' => ['id', 'card_id', 'format', 'status', 'effective_date', 'notes'],
            'card_classes' => ['card_id', 'class_id'],
            'card_talents' => ['card_id', 'talent_id'],
            'card_keywords' => ['card_id', 'keyword_id'],
            'precons' => ['id', 'name', 'set_code', 'type', 'hero_card_id', 'release_date', 'notes'],
            'precon_cards' => ['precon_id', 'card_id', 'quantity'],
            'rules_text_versions' => ['id', 'fetched_at', 'full_text', 'diff_from_previous'],
            'errata_bulletins' => ['id', 'bulletin_number', 'url', 'published_date', 'content', 'affected_card_ids', 'cached_at'],
            'meta_snapshots' => ['id', 'hero_id', 'format', 'tier', 'win_rate', 'sample_size', 'source', 'fetched_at'],
            'staple_stats' => ['id', 'hero_id', 'card_id', 'inclusion_rate', 'source', 'fetched_at'],
        ];
    }

    /**
     * @return array<string, array<int, array{columns: array<int, string>, foreign_table: string}>>
     */
    private function expectedForeignKeys(): array
    {
        return [
            'hero_profiles' => [
                ['columns' => ['hero_id'], 'foreign_table' => 'heroes'],
            ],
            'cards' => [
                ['columns' => ['card_type_id'], 'foreign_table' => 'card_types'],
                ['columns' => ['hero_profile_id'], 'foreign_table' => 'hero_profiles'],
            ],
            'card_printings' => [
                ['columns' => ['card_id'], 'foreign_table' => 'cards'],
            ],
            'card_legality' => [
                ['columns' => ['card_id'], 'foreign_table' => 'cards'],
            ],
            'card_classes' => [
                ['columns' => ['card_id'], 'foreign_table' => 'cards'],
                ['columns' => ['class_id'], 'foreign_table' => 'classes'],
            ],
            'card_talents' => [
                ['columns' => ['card_id'], 'foreign_table' => 'cards'],
                ['columns' => ['talent_id'], 'foreign_table' => 'talents'],
            ],
            'card_keywords' => [
                ['columns' => ['card_id'], 'foreign_table' => 'cards'],
                ['columns' => ['keyword_id'], 'foreign_table' => 'keywords'],
            ],
            'precons' => [
                ['columns' => ['hero_card_id'], 'foreign_table' => 'cards'],
            ],
            'precon_cards' => [
                ['columns' => ['precon_id'], 'foreign_table' => 'precons'],
                ['columns' => ['card_id'], 'foreign_table' => 'cards'],
            ],
            'meta_snapshots' => [
                ['columns' => ['hero_id'], 'foreign_table' => 'cards'],
            ],
            'staple_stats' => [
                ['columns' => ['hero_id'], 'foreign_table' => 'cards'],
                ['columns' => ['card_id'], 'foreign_table' => 'cards'],
            ],
        ];
    }

    public function test_every_data_phase_table_exists(): void
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

    public function test_card_legality_enforces_one_row_per_card_and_format(): void
    {
        $indexes = Schema::getIndexes('card_legality');

        $unique = collect($indexes)->first(
            fn (array $index) => $index['unique'] && $index['columns'] === ['card_id', 'format']
        );

        $this->assertNotNull($unique, 'card_legality is missing a unique index over [card_id, format].');
    }

    public function test_cards_source_id_is_unique(): void
    {
        $indexes = Schema::getIndexes('cards');

        $unique = collect($indexes)->first(
            fn (array $index) => $index['unique'] && $index['columns'] === ['source_id']
        );

        $this->assertNotNull($unique, 'cards is missing a unique index over [source_id].');
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function expectedCompositePrimaryKeys(): array
    {
        return [
            'card_classes' => ['card_id', 'class_id'],
            'card_talents' => ['card_id', 'talent_id'],
            'card_keywords' => ['card_id', 'keyword_id'],
            'precon_cards' => ['precon_id', 'card_id'],
        ];
    }

    public function test_join_tables_have_composite_primary_keys(): void
    {
        foreach ($this->expectedCompositePrimaryKeys() as $table => $columns) {
            $indexes = Schema::getIndexes($table);

            $primary = collect($indexes)->first(
                fn (array $index) => $index['primary'] && $index['columns'] === $columns
            );

            $this->assertNotNull($primary, "Table [{$table}] is missing a composite primary key over [".implode(',', $columns).'].');
        }
    }

    /**
     * @return array<string, string>
     */
    private function expectedUniqueColumns(): array
    {
        return [
            'card_types' => 'name',
            'classes' => 'name',
            'talents' => 'name',
            'keywords' => 'name',
        ];
    }

    public function test_lookup_table_names_are_unique(): void
    {
        foreach ($this->expectedUniqueColumns() as $table => $column) {
            $indexes = Schema::getIndexes($table);

            $unique = collect($indexes)->first(
                fn (array $index) => $index['unique'] && $index['columns'] === [$column]
            );

            $this->assertNotNull($unique, "Table [{$table}] is missing a unique index over [{$column}].");
        }
    }

    /**
     * @return array<int, array{table: string, columns: array<int, string>}>
     */
    private function expectedIndexedColumns(): array
    {
        return [
            ['table' => 'cards', 'columns' => ['name']],
            ['table' => 'cards', 'columns' => ['card_type_id']],
            ['table' => 'card_printings', 'columns' => ['set_code']],
            ['table' => 'hero_profiles', 'columns' => ['hero_id']],
            ['table' => 'precons', 'columns' => ['hero_card_id']],
            ['table' => 'meta_snapshots', 'columns' => ['hero_id', 'format']],
            ['table' => 'staple_stats', 'columns' => ['hero_id', 'card_id']],
        ];
    }

    public function test_expected_columns_are_indexed(): void
    {
        foreach ($this->expectedIndexedColumns() as $expected) {
            $indexes = Schema::getIndexes($expected['table']);

            $found = collect($indexes)->first(
                fn (array $index) => $index['columns'] === $expected['columns']
            );

            $this->assertNotNull($found, "Table [{$expected['table']}] is missing an index over [".implode(',', $expected['columns']).'].');
        }
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
}
