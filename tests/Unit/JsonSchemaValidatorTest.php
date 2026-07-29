<?php

namespace Tests\Unit;

use App\Services\Llm\JsonSchemaValidator;
use PHPUnit\Framework\TestCase;

class JsonSchemaValidatorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
        ];
    }

    public function test_valid_payload_has_no_violations(): void
    {
        $violations = (new JsonSchemaValidator)->validate(['name' => 'Rhinar', 'age' => 30], $this->schema());

        $this->assertSame([], $violations);
    }

    public function test_missing_required_property_is_reported(): void
    {
        $violations = (new JsonSchemaValidator)->validate(['age' => 30], $this->schema());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("Missing required property 'name'", $violations[0]);
    }

    public function test_wrong_property_type_is_reported(): void
    {
        $violations = (new JsonSchemaValidator)->validate(['name' => 123], $this->schema());

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString("Property 'name' expected type 'string'", implode(' ', $violations));
    }

    public function test_wrong_top_level_type_short_circuits_with_a_single_violation(): void
    {
        $violations = (new JsonSchemaValidator)->validate(['a', 'b'], $this->schema());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("Expected payload type 'object'", $violations[0]);
    }

    public function test_array_schema_accepts_a_list_payload(): void
    {
        $violations = (new JsonSchemaValidator)->validate(['a', 'b'], ['type' => 'array']);

        $this->assertSame([], $violations);
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaWithArrayProperty(): array
    {
        return [
            'type' => 'object',
            'required' => ['cited_rules'],
            'properties' => [
                'cited_rules' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    public function test_it_accepts_an_array_whose_items_all_match(): void
    {
        $violations = (new JsonSchemaValidator)->validate(['cited_rules' => ['8.5.3b', '1.2']], $this->schemaWithArrayProperty());

        $this->assertSame([], $violations);
    }

    public function test_it_reports_a_violation_for_a_wrongly_typed_array_item(): void
    {
        $violations = (new JsonSchemaValidator)->validate(['cited_rules' => ['8.5.3b', 123]], $this->schemaWithArrayProperty());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("Property 'cited_rules' item at index 1 expected type 'string', got 'integer'", $violations[0]);
    }
}
