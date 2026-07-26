<?php

namespace App\Services\Llm;

interface LlmTransport
{
    /**
     * The transport's identifying name (e.g. 'api', 'cli').
     */
    public function name(): string;

    /**
     * Send the given prompt to the LLM and return the decoded payload,
     * validated against the given JSON schema.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function complete(string $prompt, array $schema): array;
}
