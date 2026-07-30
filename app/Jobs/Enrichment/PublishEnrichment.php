<?php

namespace App\Jobs\Enrichment;

use App\Models\CardExplainer;
use App\Models\ComboPair;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Publish step: promotes `validated` rows to `published` across
 * `card_explainers`, `combo_pairs`, and `card_synergy_tags`, in place, inside
 * a single transaction so a failure never leaves the live tables
 * half-updated. Scoping every update to `status = 'validated'` makes re-runs
 * idempotent — already-`published` rows no longer match.
 */
class PublishEnrichment extends EnrichmentJob
{
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->shouldSkipWrite('dry_run')) {
            return;
        }

        $counts = DB::transaction(function (): array {
            $publishedAt = now();

            return [
                'explainers' => CardExplainer::where('status', 'validated')
                    ->update(['status' => 'published', 'published_at' => $publishedAt]),
                'combos' => ComboPair::where('status', 'validated')
                    ->update(['status' => 'published', 'published_at' => $publishedAt]),
                'synergies' => DB::table('card_synergy_tags')
                    ->where('status', 'validated')
                    ->update(['status' => 'published', 'published_at' => $publishedAt]),
            ];
        });

        Log::info('enrichment.published', [
            'step' => $this->stepKey(),
            'counts' => $counts,
        ]);
    }

    protected function stepKey(): string
    {
        return 'publish';
    }
}
