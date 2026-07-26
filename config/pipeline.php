<?php

return [
    'lock_ttl_seconds' => (int) env('PIPELINE_LOCK_TTL_SECONDS', 3600),
    'stale_run_after_minutes' => (int) env('PIPELINE_STALE_RUN_AFTER_MINUTES', 180),
    'user_agent' => 'YaFaBa-Data-Ingest/1.0 (+https://github.com/joshevensen/yafaba-api)',

    /** Source keys whose change should trigger an Enrichment AI run. */
    'enrichment_relevant_sources' => ['fab_cube_cards', 'errata_bulletins', 'fab_cr_rules', 'fabtcg_hero_selection'],

    'sources' => [
        'fab_cube_cards' => [
            'strategy' => 'github_commit',
            'repository' => 'the-fab-cube/flesh-and-blood-cards',
            'path' => 'json/english/card.json',
            'ref' => 'develop',
            'command' => 'data:ingest-cards',
        ],
        'cardvault_printings' => [
            'strategy' => 'http_fingerprint',
            'url' => 'https://api.cardvault.fabtcg.com/carddb/api/v1/advanced-search/?q=&page_size=1&orderby=-name',
            'command' => 'data:ingest-printings',
        ],
        'errata_bulletins' => [
            'strategy' => 'http_fingerprint',
            'url' => 'https://fabtcg.com/rules-and-policy-center/errata-bulletins/',
            'user_agent' => 'browser',
            'command' => 'data:ingest-errata',
        ],
        'fabtcg_hero_selection' => [
            'strategy' => 'http_fingerprint',
            'url' => 'https://fabtcg.com/api/fab/v2/heroes/?lang=en',
            'user_agent' => 'browser',
            'command' => 'data:ingest-hero-selection',
        ],
        'fab_cr_rules' => [
            'strategy' => 'http_fingerprint',
            'url' => 'https://rules.fabtcg.com/txt/latest/en-fab-cr.txt',
            'command' => 'data:ingest-rules-text',
        ],
        'tcgcsv_pricing' => ['strategy' => 'always', 'command' => 'data:ingest-pricing'],
        'meta_snapshots' => ['strategy' => 'always', 'command' => 'data:ingest-meta'],
        'fabrec_staple_stats' => ['strategy' => 'always', 'command' => 'data:ingest-staples'],
    ],
];
