<?php

namespace App\Services\Enrichment;

use App\Models\KbDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Top-k vector retrieval over kb_documents, restricted to ground_truth-trust
 * rows (rules/errata content only — never prior AI output). On pgsql this
 * ranks with a native `<=>` cosine-distance ORDER BY; on any other driver
 * (sqlite in tests, where the embedding column is plain text) it fetches the
 * candidate rows and ranks by cosine similarity computed in PHP, mirroring
 * the same driver conditional already used by the kb_documents migration.
 */
class KbRetriever
{
    public function __construct(private readonly VoyageEmbeddingClient $client) {}

    /**
     * @return Collection<int, KbDocument>
     */
    public function retrieve(string $queryText, int $topK): Collection
    {
        if (! $this->groundTruthQuery()->exists()) {
            return collect();
        }

        $queryVector = $this->client->embed([$queryText], 'query')[0];

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            return $this->groundTruthQuery()
                ->orderByRaw('embedding <=> ?::vector', [$queryVector])
                ->limit($topK)
                ->get();
        }

        $queryFloats = $this->parseVector($queryVector);

        return $this->groundTruthQuery()
            ->get()
            ->sortByDesc(fn (KbDocument $document): float => $this->cosineSimilarity($queryFloats, $this->parseVector((string) $document->embedding)))
            ->take($topK)
            ->values();
    }

    private function groundTruthQuery(): Builder
    {
        return KbDocument::query()
            ->where('trust_status', KbDocument::TRUST_GROUND_TRUTH)
            ->whereNotNull('embedding');
    }

    /**
     * @return list<float>
     */
    private function parseVector(string $literal): array
    {
        return array_map('floatval', explode(',', trim($literal, '[]')));
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $value) {
            $other = $b[$index] ?? 0.0;
            $dot += $value * $other;
            $normA += $value ** 2;
            $normB += $other ** 2;
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
