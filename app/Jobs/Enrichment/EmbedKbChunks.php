<?php

namespace App\Jobs\Enrichment;

use App\Models\KbDocument;
use App\Services\Enrichment\VoyageEmbeddingClient;
use App\Services\EnrichmentRunContext;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Embeds one slice of KbChunker chunks through Voyage AI and writes the
 * resulting kb_documents rows. One job per Voyage request — this is what
 * makes the enrichment-embedding rate limiter (queue middleware, which gates
 * job execution) actually throttle the API instead of letting a single
 * inline loop consume one token and then hammer Voyage unthrottled.
 */
class EmbedKbChunks extends EnrichmentJob
{
    /**
     * @param  list<array{source_type: string, source_ref: ?string, content: string, trust_status: string, effective_date: ?string}>  $chunks
     */
    public function __construct(EnrichmentRunContext $context, public readonly array $chunks)
    {
        parent::__construct($context);
    }

    public function handle(VoyageEmbeddingClient $client): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->chunks === []) {
            return;
        }

        $vectors = $client->embed(array_column($this->chunks, 'content'));
        $model = (string) config('enrichment.embedding.model');

        foreach ($this->chunks as $index => $chunk) {
            KbDocument::create([
                'source_type' => $chunk['source_type'],
                'source_ref' => $chunk['source_ref'],
                'content' => $chunk['content'],
                'embedding' => $vectors[$index],
                'embedding_model' => $model,
                'trust_status' => $chunk['trust_status'],
                'version' => 1,
                'effective_date' => $chunk['effective_date'],
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @return array<int, RateLimited>
     */
    public function middleware(): array
    {
        return [new RateLimited('enrichment-embedding')];
    }

    protected function stepKey(): string
    {
        return 'kb';
    }
}
