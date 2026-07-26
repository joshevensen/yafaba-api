<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\PipelineRun;
use App\Services\EnrichmentPlanner;
use App\Services\EnrichmentRunContext;
use App\Services\PipelineRunGate;
use App\Services\PipelineRunRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

#[Signature('enrich:run {--only= : Comma-separated subset of steps: kb,explainer,combo,synergy,validate,publish} {--fresh : Ignore idempotency guards and recompute everything} {--dry-run : Log planned writes without persisting or spending} {--card= : Enrich a single card end-to-end, inline} {--via=api : LLM transport: api|cli (cli is local-only)} {--triggered-by=cli : scheduled|manual|cli}')]
#[Description('Dispatch the Enrichment pipeline: a chained sequence of queued jobs, with Bus::batch for the per-card fan-out steps.')]
class EnrichRun extends Command
{
    public function __construct(
        private readonly PipelineRunGate $gate,
        private readonly PipelineRunRecorder $recorder,
        private readonly EnrichmentPlanner $planner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $triggeredBy = (string) $this->option('triggered-by');
        $via = (string) $this->option('via');
        $cardId = $this->option('card');

        try {
            $steps = $this->planner->resolveSteps($this->option('only'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($via === 'cli' && app()->environment('production')) {
            $this->error('--via=cli is refused in the production environment.');

            return Command::FAILURE;
        }

        if ($cardId !== null) {
            return $this->runSingleCard((string) $cardId, $steps, $via, $triggeredBy);
        }

        return $this->runPipeline($steps, $via, $triggeredBy);
    }

    private function runSingleCard(string $cardId, array $steps, string $via, string $triggeredBy): int
    {
        if (Card::find($cardId) === null) {
            $this->error("Unknown card: {$cardId}");

            return Command::FAILURE;
        }

        $context = new EnrichmentRunContext(
            pipelineRunId: null,
            steps: $steps,
            fresh: (bool) $this->option('fresh'),
            dryRun: (bool) $this->option('dry-run'),
            cardId: $cardId,
            via: $via,
            triggeredBy: $triggeredBy,
            promptVersion: (string) config('enrichment.prompt_version'),
        );

        $chain = $this->planner->buildChain($context);

        Bus::chain($chain)->onConnection('sync')->onQueue((string) config('enrichment.queue'))->dispatch();

        $this->info("Enriched card {$cardId} inline for steps: ".implode(', ', $steps));

        return Command::SUCCESS;
    }

    private function runPipeline(array $steps, string $via, string $triggeredBy): int
    {
        $force = (bool) $this->option('fresh') || $triggeredBy !== PipelineRun::TRIGGER_SCHEDULED;
        $decision = $this->gate->enrichmentShouldRun($force);

        if (! $decision->allowed) {
            Log::info('enrichment.gate_blocked', [
                'reason' => $decision->reason,
                'retry_after' => $decision->retryAfter?->toIso8601String(),
            ]);
            $this->warn("Enrichment run not started: {$decision->reason}");

            return Command::SUCCESS;
        }

        $run = $this->recorder->start(PipelineRun::PHASE_ENRICHMENT, 'enrich:run', $triggeredBy);

        if ($run === null) {
            $this->warn('Another enrichment run is already in progress; skipping.');

            return Command::SUCCESS;
        }

        $draftContext = new EnrichmentRunContext(
            pipelineRunId: $run->id,
            steps: $steps,
            fresh: (bool) $this->option('fresh'),
            dryRun: (bool) $this->option('dry-run'),
            cardId: null,
            via: $via,
            triggeredBy: $triggeredBy,
            promptVersion: (string) config('enrichment.prompt_version'),
        );

        $counts = $this->planner->plannedCounts($draftContext);
        $context = $draftContext->withPlannedCounts($counts);
        $chain = $this->planner->buildChain($context);
        $runId = $run->id;

        Bus::chain($chain)
            ->onQueue((string) config('enrichment.queue'))
            ->catch(static function (Throwable $e) use ($runId): void {
                $run = PipelineRun::find($runId);

                if ($run !== null) {
                    app(PipelineRunRecorder::class)->fail($run, $e->getMessage());
                }
            })
            ->dispatch();

        $this->info('Enrichment run dispatched: '.implode(', ', array_map(
            fn (string $step, int $count): string => "{$step}={$count}",
            array_keys($counts),
            array_values($counts),
        )));

        return Command::SUCCESS;
    }
}
