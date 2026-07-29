<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Local/manual-only LLM transport: shells out to the `claude` CLI in
 * headless JSON mode, validates the response against the caller's schema,
 * and re-prompts on mismatch. Refuses to run in production.
 */
class ClaudeCliTransport implements LlmTransport
{
    public function __construct(
        private readonly JsonSchemaValidator $jsonSchemaValidator,
    ) {}

    public function name(): string
    {
        return 'cli';
    }

    /**
     * Shell out to the `claude` CLI, decode its JSON envelope, and validate
     * the decoded payload against the given schema. Re-prompts (appending
     * the validation errors) up to the configured max attempts before
     * throwing. $model is ignored: the CLI selects its own model from its
     * own configuration, not from this call.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function complete(string $prompt, array $schema, ?string $model = null): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The Claude CLI transport is not permitted in production.');
        }

        $maxAttempts = (int) config('enrichment.cli.max_attempts');
        $currentPrompt = $prompt;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = Process::timeout((int) config('enrichment.cli.timeout'))
                ->run([config('enrichment.cli.binary'), '-p', $currentPrompt, '--output-format', 'json']);

            $payload = $this->decodePayload($result->output());

            if ($payload !== null) {
                $violations = $this->jsonSchemaValidator->validate($payload, $schema);

                if ($violations === []) {
                    return $payload;
                }
            } else {
                $violations = ['The CLI response could not be decoded as JSON.'];
            }

            $errors = implode(' ', $violations);
            $currentPrompt = $prompt."\n\nYour previous response was invalid: {$errors}. Please respond again, strictly matching the required schema.";
        }

        throw new SchemaMismatchException("The Claude CLI transport did not return a schema-valid response after {$maxAttempts} attempts.");
    }

    /**
     * Decode the CLI's double-encoded JSON envelope: the outer envelope is
     * decoded first, then its 'result' string is decoded again to obtain
     * the actual payload. Returns null when decoding fails at either step.
     *
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $output): ?array
    {
        $envelope = json_decode($output, true);

        if (! is_array($envelope) || ! isset($envelope['result']) || ! is_string($envelope['result'])) {
            return null;
        }

        $payload = json_decode($envelope['result'], true);

        return is_array($payload) ? $payload : null;
    }
}
