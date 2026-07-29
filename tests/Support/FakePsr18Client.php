<?php

namespace Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A queue-driven fake PSR-18 HTTP client. Used to drive ApiLlmTransport
 * deterministically without real network calls: Anthropic\Client::$messages
 * is a typed final class and cannot be mocked, so the PSR-18 transporter is
 * the only test seam.
 */
class FakePsr18Client implements ClientInterface
{
    /** @var array<int, ResponseInterface> */
    private array $queue;

    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param  list<array<string, mixed>>  $jsonBodies  each becomes a 200 response with this array json-encoded as the body
     */
    public function __construct(array $jsonBodies)
    {
        $this->queue = array_map(
            fn (array $body): ResponseInterface => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body)),
            $jsonBodies,
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new RuntimeException('FakePsr18Client has no more queued responses.');
        }

        return array_shift($this->queue);
    }
}
