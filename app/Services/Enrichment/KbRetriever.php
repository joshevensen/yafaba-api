<?php

namespace App\Services\Enrichment;

use App\Models\KbDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Top-k vector retrieval over kb_documents. Defaults to ground_truth-trust
 * rows (rules/errata content only — never prior AI output) with no
 * source_type filter, for backward compatibility with existing callers, but
 * accepts any trust_status/source_type combination (e.g. validated combo or
 * synergy notes). On pgsql this ranks with a native `<=>` cosine-distance
 * ORDER BY; on any other driver (sqlite in tests, where the embedding column
 * is plain text) it fetches the candidate rows and ranks by cosine
 * similarity computed in PHP, mirroring the same driver conditional already
 * used by the kb_documents migration.
 */
class KbRetriever
{
    public function __construct(private readonly VoyageEmbeddingClient $client, private readonly VectorSimilarity $similarity) {}

    /**
     * @return Collection<int, KbDocument>
     */
    public function retrieve(string $queryText, int $topK, string $trustStatus = KbDocument::TRUST_GROUND_TRUTH, ?string $sourceType = null): Collection
    {
        if (! $this->candidateQuery($trustStatus, $sourceType)->exists()) {
            return collect();
        }

        $queryVector = $this->client->embed([$queryText], 'query')[0];

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            return $this->candidateQuery($trustStatus, $sourceType)
                ->orderByRaw('embedding <=> ?::vector', [$queryVector])
                ->limit($topK)
                ->get();
        }

        $queryFloats = $this->similarity->parseVector($queryVector);

        return $this->candidateQuery($trustStatus, $sourceType)
            ->get()
            ->sortByDesc(fn (KbDocument $document): float => $this->similarity->cosineSimilarity($queryFloats, $this->similarity->parseVector((string) $document->embedding)))
            ->take($topK)
            ->values();
    }

    private function candidateQuery(string $trustStatus, ?string $sourceType): Builder
    {
        $query = KbDocument::query()
            ->where('trust_status', $trustStatus)
            ->whereNotNull('embedding');

        if ($sourceType !== null) {
            $query->where('source_type', $sourceType);
        }

        return $query;
    }
}
