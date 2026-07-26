<?php

namespace Tests\Feature;

use App\Services\Llm\ApiLlmTransport;
use App\Services\Llm\ClaudeCliTransport;
use App\Services\Llm\LlmTransport;
use App\Services\Llm\LlmTransportFactory;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class LlmTransportFactoryTest extends TestCase
{
    public function test_api_resolves_to_the_api_transport(): void
    {
        $transport = app(LlmTransportFactory::class)->make('api');

        $this->assertInstanceOf(ApiLlmTransport::class, $transport);
        $this->assertSame('api', $transport->name());
    }

    public function test_cli_resolves_to_the_claude_cli_transport(): void
    {
        $transport = app(LlmTransportFactory::class)->make('cli');

        $this->assertInstanceOf(ClaudeCliTransport::class, $transport);
        $this->assertSame('cli', $transport->name());
    }

    public function test_unknown_transport_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(LlmTransportFactory::class)->make('bogus');
    }

    public function test_api_transport_placeholder_throws_a_clear_not_configured_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No LLM API client is configured yet');

        (new ApiLlmTransport)->complete('prompt', ['type' => 'object']);
    }

    public function test_llm_transport_binding_resolves_from_the_configured_default(): void
    {
        config(['enrichment.transport' => 'api']);

        $this->assertInstanceOf(ApiLlmTransport::class, app(LlmTransport::class));
    }
}
