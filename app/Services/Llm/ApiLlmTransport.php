<?php

namespace App\Services\Llm;

use Anthropic\Client;
use Anthropic\Messages\ToolUseBlock;
use RuntimeException;

/**
 * API-based LLM transport backed by the anthropic-ai/sdk client. Forces a
 * single tool call (rather than the SDK's typed StructuredOutputModel path)
 * so the caller's schema.json can be handed through as a raw array, matching
 * LlmTransport::complete()'s array-schema contract. Validates the returned
 * tool input against the schema and re-prompts on mismatch, mirroring
 * ClaudeCliTransport's retry loop.
 */
class ApiLlmTransport implements LlmTransport
{
    private const TOOL_NAME = 'emit_result';

    public function __construct(
        private readonly Client $client,
        private readonly JsonSchemaValidator $jsonSchemaValidator,
    ) {}

    public function name(): string
    {
        return 'api';
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function complete(string $prompt, array $schema, ?string $model = null): array
    {
        if ((string) config('services.anthropic.key') === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $resolvedModel = $model ?? (string) config('enrichment.models.default');
        $maxAttempts = (int) config('enrichment.api.max_attempts');
        $currentPrompt = $prompt;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $message = $this->client->messages->create(
                maxTokens: (int) config('enrichment.llm.max_tokens'),
                messages: [['role' => 'user', 'content' => $currentPrompt]],
                model: $resolvedModel,
                toolChoice: ['type' => 'tool', 'name' => self::TOOL_NAME],
                tools: [[
                    'name' => self::TOOL_NAME,
                    'description' => 'Emit the structured result for this task.',
                    'inputSchema' => $schema,
                ]],
            );

            $toolUse = null;

            foreach ($message->content as $block) {
                if ($block instanceof ToolUseBlock) {
                    $toolUse = $block;

                    break;
                }
            }

            if ($toolUse === null) {
                $violations = ['The response did not contain a tool_use block.'];
            } else {
                $violations = $this->jsonSchemaValidator->validate($toolUse->input, $schema);

                if ($violations === []) {
                    return $toolUse->input;
                }
            }

            $errors = implode(' ', $violations);
            $currentPrompt = $prompt."\n\nYour previous response was invalid: {$errors}. Please respond again, strictly matching the required schema.";
        }

        throw new SchemaMismatchException("The Anthropic API transport did not return a schema-valid response after {$maxAttempts} attempts.");
    }
}
