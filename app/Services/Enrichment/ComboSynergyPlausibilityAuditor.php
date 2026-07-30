<?php

namespace App\Services\Enrichment;

use App\Models\ComboPair;
use Illuminate\Support\Facades\DB;

/**
 * Interim vector-similarity plausibility audit for draft `combo_pairs` and
 * `card_synergy_tags` rows.
 *
 * This is a stand-in for real self-play win-rate validation
 * (`combo_pairs.self_play_win_rate_delta`), which is blocked on the Play
 * phase's forked game engine (see issue #36). The `plausibility_score` this
 * audit writes is informational data for a future human spot-check queue —
 * it is never itself a pass/fail promotion gate.
 */
class ComboSynergyPlausibilityAuditor
{
    public function __construct(
        private readonly VoyageEmbeddingClient $client,
        private readonly VectorSimilarity $similarity,
    ) {}

    /**
     * Scores every draft combo_pairs row by the cosine similarity between
     * its two cards' functional_text embeddings, skipping rows with a
     * missing card or empty functional_text.
     *
     * @return int number of combo_pairs rows scored
     */
    public function auditComboPairs(): int
    {
        $comboPairs = ComboPair::with(['cardA', 'cardB'])->where('status', 'draft')->get();

        $scorable = $comboPairs->filter(function (ComboPair $comboPair): bool {
            if ($comboPair->cardA === null || $comboPair->cardB === null) {
                return false;
            }

            return trim((string) $comboPair->cardA->functional_text) !== ''
                && trim((string) $comboPair->cardB->functional_text) !== '';
        })->values();

        if ($scorable->isEmpty()) {
            return 0;
        }

        $texts = [];

        foreach ($scorable as $comboPair) {
            $texts[] = $comboPair->cardA->functional_text;
            $texts[] = $comboPair->cardB->functional_text;
        }

        $vectors = $this->embedInBatches($texts);

        foreach ($scorable as $index => $comboPair) {
            $vectorA = $this->similarity->parseVector($vectors[$index * 2]);
            $vectorB = $this->similarity->parseVector($vectors[$index * 2 + 1]);

            $comboPair->update(['plausibility_score' => $this->similarity->cosineSimilarity($vectorA, $vectorB)]);
        }

        return $scorable->count();
    }

    /**
     * Scores every draft card_synergy_tags row by the cosine similarity
     * between the card's functional_text and the synergy tag's description,
     * skipping rows where either text is empty.
     *
     * @return int number of card_synergy_tags rows scored
     */
    public function auditSynergyTags(): int
    {
        $rows = DB::table('card_synergy_tags')
            ->join('synergy_tags', 'synergy_tags.id', '=', 'card_synergy_tags.synergy_tag_id')
            ->join('cards', 'cards.id', '=', 'card_synergy_tags.card_id')
            ->where('card_synergy_tags.status', 'draft')
            ->select([
                'card_synergy_tags.card_id',
                'card_synergy_tags.synergy_tag_id',
                'cards.functional_text',
                'synergy_tags.description',
            ])
            ->get();

        $scorable = $rows->filter(fn (object $row): bool => trim((string) $row->functional_text) !== ''
            && trim((string) $row->description) !== '')
            ->values();

        if ($scorable->isEmpty()) {
            return 0;
        }

        $texts = [];

        foreach ($scorable as $row) {
            $texts[] = $row->functional_text;
            $texts[] = $row->description;
        }

        $vectors = $this->embedInBatches($texts);

        foreach ($scorable as $index => $row) {
            $vectorA = $this->similarity->parseVector($vectors[$index * 2]);
            $vectorB = $this->similarity->parseVector($vectors[$index * 2 + 1]);
            $score = $this->similarity->cosineSimilarity($vectorA, $vectorB);

            DB::table('card_synergy_tags')
                ->where('card_id', $row->card_id)
                ->where('synergy_tag_id', $row->synergy_tag_id)
                ->update(['plausibility_score' => $score]);
        }

        return $scorable->count();
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
