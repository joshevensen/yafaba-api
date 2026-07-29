<?php

return [
    'min_run_interval_hours' => (int) env('ENRICHMENT_MIN_RUN_INTERVAL_HOURS', 24),

    /** Authoritative order of enrich:run steps; --only is validated against and re-ordered by this list. */
    'steps' => ['kb', 'explainer', 'combo', 'synergy', 'validate', 'publish'],

    'queue' => env('ENRICHMENT_QUEUE', 'enrichment'),

    'prompt_version' => env('ENRICHMENT_PROMPT_VERSION', 'v0'),

    /** Default LLM transport for enrich:run --via. */
    'transport' => env('ENRICHMENT_TRANSPORT', 'api'),

    'job' => [
        'tries' => (int) env('ENRICHMENT_JOB_TRIES', 3),
        'backoff' => [60, 300, 900],
    ],

    'rate_limits' => [
        'llm' => [
            'allow' => (int) env('ENRICHMENT_LLM_PER_MINUTE', 60),
            'every_seconds' => 60,
        ],
        'embedding' => [
            'allow' => (int) env('ENRICHMENT_EMBEDDING_PER_MINUTE', 120),
            'every_seconds' => 60,
        ],
    ],

    /** Intended worker count for the enrichment queue; Laravel batches have no native concurrency cap. */
    'batch_concurrency' => (int) env('ENRICHMENT_BATCH_CONCURRENCY', 5),

    'embedding' => [
        'model' => env('ENRICHMENT_EMBEDDING_MODEL', 'voyage-3.5'),
        'dimensions' => 1024,
        'batch_size' => (int) env('ENRICHMENT_EMBEDDING_BATCH_SIZE', 64),
        'min_chunk_chars' => (int) env('ENRICHMENT_MIN_CHUNK_CHARS', 200),
        'max_chunk_chars' => (int) env('ENRICHMENT_MAX_CHUNK_CHARS', 4000),
    ],

    'cli' => [
        'binary' => env('CLAUDE_CLI_BINARY', 'claude'),
        'timeout' => (int) env('CLAUDE_CLI_TIMEOUT', 180),
        'max_attempts' => (int) env('CLAUDE_CLI_MAX_ATTEMPTS', 3),
    ],
];
