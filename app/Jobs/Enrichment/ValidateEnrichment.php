<?php

namespace App\Jobs\Enrichment;

use App\Models\CardExplainer;
use App\Models\ComboPair;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stub for the validation step. Counts draft-status rows and logs them.
 *
 * This stub never throws. A later issue adds real rules-grounding checks;
 * throwing from this job is what will halt the chain before
 * PublishEnrichment runs, per the chain-as-gate design.
 */
class ValidateEnrichment extends EnrichmentJob
{
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->shouldSkipWrite('dry_run')) {
            return;
        }

        Log::info('enrichment.stub', [
            'step' => $this->stepKey(),
            'counts' => [
                'explainers' => CardExplainer::where('status', 'draft')->count(),
                'combos' => ComboPair::where('status', 'draft')->count(),
                'synergies' => DB::table('card_synergy_tags')->where('status', 'draft')->count(),
            ],
        ]);
    }

    protected function stepKey(): string
    {
        return 'validate';
    }
}
