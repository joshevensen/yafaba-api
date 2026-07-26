<?php

namespace App\Jobs\Enrichment;

use App\Models\CardExplainer;
use App\Models\ComboPair;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stub for the publish step. Wraps a no-op transaction that a future issue
 * will use to promote validated rows to published status.
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

        DB::transaction(function (): void {
            // Future issue: promote validated rows to published status.
        });

        Log::info('enrichment.stub', [
            'step' => $this->stepKey(),
            'counts' => [
                'explainers' => CardExplainer::where('status', 'validated')->count(),
                'combos' => ComboPair::where('status', 'validated')->count(),
                'synergies' => DB::table('card_synergy_tags')->where('status', 'validated')->count(),
            ],
        ]);
    }

    protected function stepKey(): string
    {
        return 'publish';
    }
}
