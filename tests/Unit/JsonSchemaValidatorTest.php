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
}
