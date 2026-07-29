<?php

namespace App\Services\Enrichment;

use App\Models\Card;
use Illuminate\Support\Collection;

/**
 * Pools cards that share at least one class or talent with a target card,
 * then ranks that pool by embedding cosine similarity against the target's
 * functional_text, returning the top-k most similar cards as combo-pair
 * candidates. Never calls Voyage when there is no possible pool (no shared
 * classes/talents, no functional_text, or an empty pool after filtering).
 */
class ComboCandidateRetriever
{
    public function __construct(private readonly VoyageEmbeddingClient $client, private readonly VectorSimilarity $similarity) {}

    /**
     * @return Collection<int, Card>
     */
    public function retrieve(Card $card, int $topK, int $poolLimit): Collection
    {
        $classIds = $card->classes->pluck('id')->all();
        $talentIds = $card->talents->pluck('id')->all();

        if (($classIds === [] && $talentIds === []) || trim((string) $card->functional_text) === '') {
            return collect();
        }

        $pool = Card::query()
            ->where('id', '!=', $card->id)
            ->where(function ($query) use ($classIds, $talentIds): void {
                if ($classIds !== []) {
                    $query->orWhereHas('classes', fn ($c) => $c->whereIn('classes.id', $classIds));
                }

                if ($talentIds !== []) {
                    $query->orWhereHas('talents', fn ($t) => $t->whereIn('talents.id', $talentIds));
                }
            })
            ->whereNotNull('functional_text')
            ->where('functional_text', '!=', '')
            ->orderBy('id')
            ->limit($poolLimit)
            ->get();

        if ($pool->isEmpty()) {
            return collect();
        }

        $texts = [$card->functional_text, ...$pool->pluck('functional_text')->all()];
        $vectors = $this->embedInBatches($texts);

        $targetVector = $this->similarity->parseVector($vectors[0]);
        $poolVectors = array_slice($vectors, 1);

        return $pool
            ->values()
            ->sortByDesc(fn (Card $candidate, int $index): float => $this->similarity->cosineSimilarity($targetVector, $this->similarity->parseVector($poolVectors[$index])))
            ->take($topK)
            ->values();
    }

    /**
     * @param  list<string>  $texts
     * @return list<string>
     */
    private function embedInBatches(array $texts): array
    {
        $batchSize = max(1, (int) config('enrichment.embedding.batch_size'));
        $vectors = [];

        foreach (array_chunk($texts, $batchSize) as $slice) {
            $vectors = array_merge($vectors, $this->client->embed($slice, 'document'));
        }

        return $vectors;
    }
}
