<?php

namespace App\Services\Llm;

use RuntimeException;

/**
 * Placeholder API-based LLM transport. No Anthropic/Voyage SDK client is
 * wired up yet; see the AI task issues for the follow-up work that will
 * implement this transport.
 */
class ApiLlmTransport implements LlmTransport
{
    public function name(): string
    {
        return 'api';
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function complete(string $prompt, array $schema): array
    {
        throw new RuntimeException('No LLM API client is configured yet; see the AI task issues.');
    }
}
