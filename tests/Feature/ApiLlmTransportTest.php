<?php

namespace Tests\Feature;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Services\Llm\ApiLlmTransport;
use App\Services\Llm\SchemaMismatchException;
use Tests\Support\FakePsr18Client;
use Tests\TestCase;

class ApiLlmTransportTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['explainer_text'],
            'properties' => [
                'explainer_text' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function messageBody(array $content): array
    {
        return [
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'content' => [$content],
            'stop_reason' => 'tool_use',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function toolUseBlock(array $input): array
    {
        return ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'emit_result', 'input' => $input];
    }

    /**
     * @param  list<array<string, mixed>>  $bodies
     */
    private function bindTransport(array $bodies): FakePsr18Client
    {
        $fake = new FakePsr18Client($bodies);

        $this->app->instance(Client::class, new Client(
            apiKey: 'test-anthropic-key',
            requestOptions: RequestOptions::with(transporter: $fake),
        ));

        return $fake;
    }

    public function test_it_sends_a_forced_tool_call_with_the_given_schema_and_model(): void
    {
        $fake = $this->bindTransport([$this->messageBody($this->toolUseBlock(['explainer_text' => 'Because…']))]);

        app(ApiLlmTransport::class)->complete('Explain this card.', $this->schema(), 'claude-test-model');

        $body = json_decode((string) $fake->requests[0]->getBody(), true);

        $this->assertSame('claude-test-model', $body['model']);
        $this->assertSame(['type' => 'tool', 'name' => 'emit_result'], $body['tool_choice']);
        $this->assertSame($this->schema(), $body['tools'][0]['input_schema']);
    }

    public function test_it_returns_the_tool_use_input_on_the_happy_path(): void
    {
        $this->bindTransport([$this->messageBody($this->toolUseBlock(['explainer_text' => 'Because…']))]);

        $result = app(ApiLlmTransport::class)->complete('Explain this card.', $this->schema(), 'claude-test-model');

        $this->assertSame(['explainer_text' => 'Because…'], $result);
    }

    public function test_it_retries_after_a_schema_invalid_response_and_then_succeeds(): void
    {
        $fake = $this->bindTransport([
            $this->messageBody($this->toolUseBlock(['explainer_text' => 123])),
            $this->messageBody($this->toolUseBlock(['explainer_text' => 'Because…'])),
        ]);

        $result = app(ApiLlmTransport::class)->complete('Explain this card.', $this->schema(), 'claude-test-model');

        $this->assertSame(['explainer_text' => 'Because…'], $result);
        $this->assertCount(2, $fake->requests);
    }

    public function test_it_throws_a_schema_mismatch_exception_after_max_attempts(): void
    {
        config(['enrichment.api.max_attempts' => 2]);

        $fake = $this->bindTransport([
            $this->messageBody($this->toolUseBlock(['explainer_text' => 123])),
            $this->messageBody($this->toolUseBlock(['explainer_text' => 456])),
        ]);

        $this->expectException(SchemaMismatchException::class);

        try {
            app(ApiLlmTransport::class)->complete('Explain this card.', $this->schema(), 'claude-test-model');
        } finally {
            $this->assertCount(2, $fake->requests);
        }
    }
}
