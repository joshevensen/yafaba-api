<?php

namespace Tests\Feature;

use App\Services\Llm\ClaudeCliTransport;
use App\Services\Llm\SchemaMismatchException;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class ClaudeCliTransportTest extends TestCase
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
            ],
        ];
    }

    private function expectedCommand(string $prompt): array
    {
        return [config('enrichment.cli.binary'), '-p', $prompt, '--output-format', 'json'];
    }

    private function envelope(array $payload): string
    {
        return json_encode(['result' => json_encode($payload)]);
    }

    public function test_it_returns_the_validated_payload_on_the_happy_path(): void
    {
        $prompt = 'Describe this card.';
        $payload = ['name' => 'Rhinar'];

        Process::fake([
            '*' => $this->envelope($payload),
        ]);

        $result = app(ClaudeCliTransport::class)->complete($prompt, $this->schema());

        $this->assertSame($payload, $result);

        Process::assertRan(fn ($process) => $process->command === $this->expectedCommand($prompt));
    }

    public function test_it_retries_after_an_invalid_response_and_then_succeeds(): void
    {
        $prompt = 'Describe this card.';
        $validPayload = ['name' => 'Rhinar'];

        Process::fake([
            '*' => Process::sequence()
                ->push($this->envelope(['name' => 123]))
                ->push($this->envelope($validPayload)),
        ]);

        $result = app(ClaudeCliTransport::class)->complete($prompt, $this->schema());

        $this->assertSame($validPayload, $result);

        Process::assertRanTimes(fn ($process) => $process->command[0] === config('enrichment.cli.binary'), 2);
    }

    public function test_it_throws_after_exhausting_retries_on_persistently_invalid_responses(): void
    {
        $prompt = 'Describe this card.';

        Process::fake([
            '*' => $this->envelope(['name' => 123]),
        ]);

        $this->expectException(SchemaMismatchException::class);

        try {
            app(ClaudeCliTransport::class)->complete($prompt, $this->schema());
        } finally {
            Process::assertRanTimes(
                fn ($process) => $process->command[0] === config('enrichment.cli.binary'),
                config('enrichment.cli.max_attempts')
            );
        }
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        Process::fake();

        $this->expectException(RuntimeException::class);

        try {
            app(ClaudeCliTransport::class)->complete('Describe this card.', $this->schema());
        } finally {
            Process::assertNothingRan();
        }
    }
}
