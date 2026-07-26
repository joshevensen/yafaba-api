<?php

namespace Tests\Feature;

use App\Models\SourceSyncState;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PipelineSchemaMigrationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<int, string>>
     */
    private function expectedColumns(): array
    {
        return [
            'source_sync_state' => ['id', 'source_key', 'fingerprint', 'fingerprint_type', 'pulled_fingerprint', 'last_checked_at', 'last_changed_at', 'last_pulled_at', 'last_error'],
            'pipeline_runs' => ['id', 'phase', 'command', 'started_at', 'finished_at', 'status', 'triggered_by', 'counts', 'is_running', 'error'],
        ];
    }

    public function test_source_sync_state_and_pipeline_runs_tables_exist_with_expected_columns(): void
    {
        foreach ($this->expectedColumns() as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Expected table [{$table}] to exist.");

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

    public function test_source_sync_state_source_key_is_unique(): void
    {
        $indexes = Schema::getIndexes('source_sync_state');

        $unique = collect($indexes)->first(
            fn (array $index) => $index['unique'] && $index['columns'] === ['source_key']
        );

        $this->assertNotNull($unique, 'source_sync_state is missing a unique index over [source_key].');

        SourceSyncState::factory()->create(['source_key' => 'duplicate_key']);

        $this->expectException(QueryException::class);

        SourceSyncState::factory()->create(['source_key' => 'duplicate_key']);
    }
}
