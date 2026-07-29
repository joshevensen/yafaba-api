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
     * validated against the given JSON schema. $model is the caller-selected
     * model id; transports that cannot honour it (e.g. the CLI transport,
     * which uses its own configured model) ignore it.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function complete(string $prompt, array $schema, ?string $model = null): array;
}
