<?php

namespace App\Services\Llm;

use InvalidArgumentException;

/**
 * Resolves the LlmTransport implementation for a --via value.
 */
class LlmTransportFactory
{
    public function __construct(
        private readonly ApiLlmTransport $apiLlmTransport,
        private readonly ClaudeCliTransport $claudeCliTransport,
    ) {}

    /**
     * Resolve the LlmTransport implementation for the given transport key.
     */
    public function make(string $via): LlmTransport
    {
        return match ($via) {
            'api' => $this->apiLlmTransport,
            'cli' => $this->claudeCliTransport,
            default => throw new InvalidArgumentException("Unknown LLM transport: {$via}"),
        };
    }
}
