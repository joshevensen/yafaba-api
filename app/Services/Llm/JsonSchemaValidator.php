<?php

namespace App\Services\Llm;

class JsonSchemaValidator
{
    /**
     * Validate the given decoded payload against a minimal JSON-Schema
     * subset: top-level 'type' (object|array), a 'required' property list,
     * and per-property 'type' checks via 'properties'. Returns a list of
     * human-readable violation strings; an empty array means the payload is
     * valid.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    public function validate(mixed $payload, array $schema): array
    {
        $violations = [];

        $expectedType = $schema['type'] ?? null;

        if ($expectedType !== null && ! $this->matchesType($payload, $expectedType)) {
            $violations[] = "Expected payload type '{$expectedType}', got '".$this->describeType($payload)."'.";

            return $violations;
        }

        if ($expectedType === 'object' && is_array($payload)) {
            foreach ($schema['required'] ?? [] as $requiredProperty) {
                if (! array_key_exists($requiredProperty, $payload)) {
                    $violations[] = "Missing required property '{$requiredProperty}'.";
                }
            }

            foreach ($schema['properties'] ?? [] as $property => $propertySchema) {
                if (! array_key_exists($property, $payload)) {
                    continue;
                }

                $propertyType = $propertySchema['type'] ?? null;

                if ($propertyType !== null && ! $this->matchesType($payload[$property], $propertyType)) {
                    $violations[] = "Property '{$property}' expected type '{$propertyType}', got '".$this->describeType($payload[$property])."'.";

                    continue;
                }

                if ($propertyType === 'array' && isset($propertySchema['items']['type'])) {
                    $violations = array_merge(
                        $violations,
                        $this->validateItems($property, $payload[$property], $propertySchema['items']['type']),
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Validate every element of an array property against the schema's
     * 'items.type', returning one violation per mismatched element.
     *
     * @return array<int, string>
     */
    private function validateItems(string $property, mixed $value, string $itemType): array
    {
        if (! is_array($value)) {
            return [];
        }

        $violations = [];

        foreach (array_values($value) as $index => $item) {
            if (! $this->matchesType($item, $itemType)) {
                $violations[] = "Property '{$property}' item at index {$index} expected type '{$itemType}', got '".$this->describeType($item)."'.";
            }
        }

        return $violations;
    }

    /**
     * Whether the given value matches the given JSON-Schema primitive type.
     */
    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ! array_is_list($value),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => true,
        };
    }

    /**
     * Describe the JSON-Schema type of the given value, for error messages.
     */
    private function describeType(mixed $value): string
    {
        return match (true) {
            is_array($value) && array_is_list($value) => 'array',
            is_array($value) => 'object',
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            default => gettype($value),
        };
    }
}
