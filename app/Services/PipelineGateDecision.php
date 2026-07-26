<?php

namespace App\Services;

use Carbon\CarbonImmutable;

final class PipelineGateDecision
{
    public const REASON_FORCED = 'forced';

    public const REASON_SOURCE_CHANGED = 'source_changed';

    public const REASON_NO_PRIOR_RUN = 'no_prior_run';

    public const REASON_COOLDOWN = 'cooldown';

    public const REASON_ALREADY_RUNNING = 'already_running';

    public const REASON_NO_RELEVANT_CHANGES = 'no_relevant_changes';

    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly ?CarbonImmutable $retryAfter = null,
    ) {}
}
