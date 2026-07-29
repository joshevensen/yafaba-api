<?php

namespace App\Services\Enrichment;

/**
 * Stateless vector-similarity math shared by retrievers that rank
 * pgvector-literal embeddings in PHP (e.g. on sqlite in tests, where the
 * embedding column is plain text rather than a native `vector` type).
 */
class VectorSimilarity
{
    /**
     * @return list<float>
     */
    public function parseVector(string $literal): array
    {
        return array_map('floatval', explode(',', trim($literal, '[]')));
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public function cosineSimilarity(array $a, array $b): float
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
