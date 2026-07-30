<?php

namespace App\Services\Enrichment;

use App\Models\CardExplainer;
use App\Models\RulesTextVersion;

/**
 * Deterministic half of the explainer rules-grounding check: verifies every
 * entry in a `card_explainers` row's `cited_rules` array actually exists as a
 * section in the current Comprehensive Rules (CR) snapshot.
 *
 * This deliberately reads `rules_text_versions.full_text` directly (via
 * `KbChunker::parseSections()`) rather than `kb_documents`, so the check is
 * independent of whether the `kb` enrichment step / embeddings have run
 * recently — this is the "current CR snapshot" per the issue's
 * grounding-check requirement.
 */
class ExplainerGroundingChecker
{
    public function __construct(private readonly KbChunker $chunker) {}

    /**
     * @return list<string> cited rules that do not exist in the current CR snapshot
     */
    public function unknownCitedRules(CardExplainer $explainer): array
    {
        /** @var list<string> $citedRules */
        $citedRules = [];

        foreach ((array) $explainer->cited_rules as $citedRule) {
            $citedRule = trim((string) $citedRule);

            if ($citedRule !== '') {
                $citedRules[] = $citedRule;
            }
        }

        $version = RulesTextVersion::query()
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->first();

        if ($version === null || trim((string) $version->full_text) === '') {
            return array_values(array_unique($citedRules));
        }

        $validKeys = [];

        foreach (array_keys($this->chunker->parseSections((string) $version->full_text)) as $key) {
            $validKeys[$key] = true;
            $validKeys[preg_replace('/#\d+$/', '', $key)] = true;
        }

        $unknown = [];

        foreach ($citedRules as $citedRule) {
            if (! isset($validKeys[$citedRule]) && ! in_array($citedRule, $unknown, true)) {
                $unknown[] = $citedRule;
            }
        }

        return array_values($unknown);
    }
}
