<?php

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Voyage AI embeddings REST API. No SDK dependency —
 * a single POST endpoint, following the repo's convention of hand-rolling
 * external API calls (see SourceFreshnessChecker).
 */
class VoyageEmbeddingClient
{
    /**
     * Embeds each input text and returns pgvector-literal-formatted vectors
     * in the same order as the input.
     *
     * @param  list<string>  $texts
     * @return list<string>
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $texts = array_values($texts);

        $response = Http::withToken((string) config('services.voyageai.key'))
            ->timeout(60)
            ->post(rtrim((string) config('services.voyageai.api_url'), '/').'/embeddings', [
                'model' => config('enrichment.embedding.model'),
                'input' => $texts,
                'input_type' => 'document',
                'truncation' => true,
            ]);

        if (! $response->successful()) {
            throw new VoyageEmbeddingException("Voyage embeddings request failed with status {$response->status()}");
        }

        $data = $response->json('data');

        if (! is_array($data) || count($data) !== count($texts)) {
            throw new VoyageEmbeddingException('Voyage embeddings response returned an unexpected number of vectors.');
        }

        usort($data, fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        $dimensions = (int) config('enrichment.embedding.dimensions');
        $vectors = [];

        foreach ($data as $item) {
            $embedding = $item['embedding'] ?? null;

            if (! is_array($embedding) || count($embedding) !== $dimensions) {
                throw new VoyageEmbeddingException("Voyage embedding vector did not have the expected {$dimensions} dimensions.");
            }

            $vectors[] = $this->toPgVectorLiteral($embedding);
        }

        return $vectors;
    }

    /**
     * Formats a vector as the bracketed comma-separated string Postgres
     * coerces to `vector` and KbDocumentFactory::embedded() also uses.
     *
     * @param  array<int, float>  $floats
     */
    public function toPgVectorLiteral(array $floats): string
    {
        return '['.implode(',', $floats).']';
    }
}
