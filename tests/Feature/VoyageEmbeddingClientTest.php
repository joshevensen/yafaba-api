<?php

namespace Tests\Feature;

use App\Services\Enrichment\VoyageEmbeddingClient;
use App\Services\Enrichment\VoyageEmbeddingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoyageEmbeddingClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.voyageai.key' => 'test-voyage-key',
            'services.voyageai.api_url' => 'https://api.voyageai.test/v1',
            'enrichment.embedding.model' => 'voyage-3.5',
            'enrichment.embedding.dimensions' => 1024,
        ]);
    }

    private function client(): VoyageEmbeddingClient
    {
        return app(VoyageEmbeddingClient::class);
    }

    public function test_it_posts_to_the_configured_endpoint_with_expected_shape(): void
    {
        Http::fake([
            'api.voyageai.test/v1/embeddings' => Http::response([
                'data' => [
                    ['index' => 0, 'embedding' => array_fill(0, 1024, 0.5)],
                ],
            ]),
        ]);

        $this->client()->embed(['hello world']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.voyageai.test/v1/embeddings'
                && $request->hasHeader('Authorization', 'Bearer test-voyage-key')
                && $request['model'] === 'voyage-3.5'
                && $request['input'] === ['hello world']
                && $request['input_type'] === 'document';
        });
    }

    public function test_it_returns_vectors_in_pgvector_literal_format_and_input_order(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['index' => 1, 'embedding' => array_fill(0, 1024, 0.2)],
                    ['index' => 0, 'embedding' => array_fill(0, 1024, 0.1)],
                ],
            ]),
        ]);

        $vectors = $this->client()->embed(['first', 'second']);

        $this->assertCount(2, $vectors);
        $this->assertSame('['.implode(',', array_fill(0, 1024, 0.1)).']', $vectors[0]);
        $this->assertSame('['.implode(',', array_fill(0, 1024, 0.2)).']', $vectors[1]);
    }

    public function test_non_2xx_response_throws(): void
    {
        Http::fake(['*' => Http::response('server error', 500)]);

        $this->expectException(VoyageEmbeddingException::class);

        $this->client()->embed(['hello']);
    }

    public function test_vector_count_mismatch_throws(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['index' => 0, 'embedding' => array_fill(0, 1024, 0.1)],
                ],
            ]),
        ]);

        $this->expectException(VoyageEmbeddingException::class);

        $this->client()->embed(['first', 'second']);
    }

    public function test_dimension_mismatch_throws(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['index' => 0, 'embedding' => array_fill(0, 512, 0.1)],
                ],
            ]),
        ]);

        $this->expectException(VoyageEmbeddingException::class);

        $this->client()->embed(['hello']);
    }

    public function test_empty_input_returns_empty_and_sends_nothing(): void
    {
        $this->assertSame([], $this->client()->embed([]));

        Http::assertNothingSent();
    }
}
