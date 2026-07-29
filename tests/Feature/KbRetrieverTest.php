<?php

namespace Tests\Feature;

use App\Models\KbDocument;
use App\Services\Enrichment\KbRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KbRetrieverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Small fixed dimensionality keeps the fake Voyage response and the
        // cosine-similarity ranking easy to reason about in these tests.
        config(['enrichment.embedding.dimensions' => 3]);
    }

    private function fakeQueryEmbedding(array $vector): void
    {
        Http::fake([
            '*' => Http::response(['data' => [['index' => 0, 'embedding' => $vector]]]),
        ]);
    }

    public function test_retrieve_excludes_non_ground_truth_documents(): void
    {
        $ground = KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_GROUND_TRUTH, 'embedding' => '[1,0,0]']);
        KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_VALIDATED, 'embedding' => '[1,0,0]']);
        KbDocument::factory()->create(['trust_status' => 'draft', 'embedding' => '[1,0,0]']);

        $this->fakeQueryEmbedding([1, 0, 0]);

        $results = app(KbRetriever::class)->retrieve('functional text', 8);

        $this->assertSame([$ground->id], $results->pluck('id')->all());
    }

    public function test_retrieve_limits_results_to_configured_top_k(): void
    {
        KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_GROUND_TRUTH, 'embedding' => '[1,0,0]']);
        KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_GROUND_TRUTH, 'embedding' => '[0,1,0]']);
        KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_GROUND_TRUTH, 'embedding' => '[0,0,1]']);

        $this->fakeQueryEmbedding([1, 0, 0]);

        $results = app(KbRetriever::class)->retrieve('functional text', 2);

        $this->assertCount(2, $results);
    }

    public function test_retrieve_requests_a_query_embedding(): void
    {
        KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_GROUND_TRUTH, 'embedding' => '[1,0,0]']);

        $this->fakeQueryEmbedding([1, 0, 0]);

        app(KbRetriever::class)->retrieve('functional text', 8);

        Http::assertSent(fn ($request) => $request->data()['input_type'] === 'query');
    }

    public function test_retrieve_skips_the_embedding_call_when_no_candidates_exist(): void
    {
        Http::preventStrayRequests();

        $results = app(KbRetriever::class)->retrieve('functional text', 8);

        $this->assertTrue($results->isEmpty());
        Http::assertNothingSent();
    }

    public function test_retrieve_ranks_the_most_similar_document_first(): void
    {
        $orthogonal = KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_GROUND_TRUTH, 'embedding' => '[0,1,0]']);
        $exactMatch = KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_GROUND_TRUTH, 'embedding' => '[1,0,0]']);
        $opposite = KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_GROUND_TRUTH, 'embedding' => '[-1,0,0]']);

        $this->fakeQueryEmbedding([1, 0, 0]);

        $results = app(KbRetriever::class)->retrieve('functional text', 3);

        $this->assertSame([$exactMatch->id, $orthogonal->id, $opposite->id], $results->pluck('id')->all());
    }

    public function test_retrieve_can_filter_to_validated_combo_documents(): void
    {
        $combo = KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_VALIDATED, 'source_type' => KbDocument::SOURCE_COMBO, 'embedding' => '[1,0,0]']);
        KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_VALIDATED, 'source_type' => KbDocument::SOURCE_SYNERGY, 'embedding' => '[1,0,0]']);
        KbDocument::factory()->create(['trust_status' => KbDocument::TRUST_GROUND_TRUTH, 'embedding' => '[1,0,0]']);

        $this->fakeQueryEmbedding([1, 0, 0]);

        $results = app(KbRetriever::class)->retrieve('functional text', 8, KbDocument::TRUST_VALIDATED, KbDocument::SOURCE_COMBO);

        $this->assertSame([$combo->id], $results->pluck('id')->all());
    }
}
