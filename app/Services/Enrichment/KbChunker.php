<?php

namespace App\Services\Enrichment;

use App\Models\CardExplainer;
use App\Models\ComboPair;
use App\Models\ErrataBulletin;
use App\Models\KbDocument;
use App\Models\RulesTextVersion;
use Illuminate\Support\Facades\DB;

/**
 * Splits Comprehensive Rules text, errata bulletins, and validated
 * explainer/combo/synergy notes into the chunk shape BuildKnowledgeBase
 * embeds and persists into kb_documents.
 */
class KbChunker
{
    /**
     * @return list<array{source_type: string, source_ref: ?string, content: string, trust_status: string, effective_date: ?string}>
     */
    public function chunkRulesText(RulesTextVersion $version): array
    {
        $merged = $this->mergeSmallSections($this->parseSections($version->full_text));
        $effectiveDate = $version->fetched_at?->toDateString();

        $chunks = [];

        foreach ($merged as $sourceRef => $content) {
            foreach ($this->splitLarge((string) $sourceRef, $content) as $ref => $body) {
                $chunks[] = [
                    'source_type' => KbDocument::SOURCE_CR_RULES,
                    'source_ref' => $ref,
                    'content' => $body,
                    'trust_status' => KbDocument::TRUST_GROUND_TRUTH,
                    'effective_date' => $effectiveDate,
                ];
            }
        }

        return $chunks;
    }

    /**
     * @return list<array{source_type: string, source_ref: ?string, content: string, trust_status: string, effective_date: ?string}>
     */
    public function chunkErrataBulletin(ErrataBulletin $bulletin): array
    {
        $text = trim(html_entity_decode(strip_tags((string) $bulletin->content), ENT_QUOTES | ENT_HTML5));

        if ($text === '') {
            return [];
        }

        return [[
            'source_type' => KbDocument::SOURCE_ERRATA,
            'source_ref' => $bulletin->id,
            'content' => $text,
            'trust_status' => KbDocument::TRUST_GROUND_TRUTH,
            'effective_date' => $bulletin->published_date?->toDateString(),
        ]];
    }

    /**
     * @return list<array{source_type: string, source_ref: ?string, content: string, trust_status: string, effective_date: ?string}>
     */
    public function chunkValidatedNotes(): array
    {
        return array_merge(
            $this->chunkValidatedExplainers(),
            $this->chunkValidatedCombos(),
            $this->chunkValidatedSynergies(),
        );
    }

    /**
     * Parse rules text into an ordered map of section-key => section-body.
     *
     * A line matching a numbered rule/glossary pattern (e.g. `1.0.1a`,
     * `1.-1.293`) opens a new section; every other line — chapter headings,
     * prose, examples, blank lines — is appended to the currently-open
     * section's body. Lines before the first section accumulate under the
     * reserved `preamble` key.
     *
     * @return array<string, string>
     */
    public function parseSections(string $text): array
    {
        $sections = [];
        $currentKey = 'preamble';

        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^(\d+(?:\.-?\d+){1,3}[a-z]?)\s/', $line, $matches) === 1) {
                $currentKey = $matches[1];
            }

            $sections[$currentKey] = isset($sections[$currentKey])
                ? $sections[$currentKey]."\n".$line
                : $line;
        }

        return $sections;
    }

    /**
     * @return list<array{source_type: string, source_ref: ?string, content: string, trust_status: string, effective_date: ?string}>
     */
    private function chunkValidatedExplainers(): array
    {
        $chunks = [];

        foreach (CardExplainer::where('status', 'validated')->get() as $explainer) {
            if (trim((string) $explainer->explainer_text) === '') {
                continue;
            }

            $chunks[] = [
                'source_type' => KbDocument::SOURCE_EXPLAINER,
                'source_ref' => $explainer->card_id,
                'content' => $explainer->explainer_text,
                'trust_status' => KbDocument::TRUST_VALIDATED,
                'effective_date' => $explainer->validated_at?->toDateString(),
            ];
        }

        return $chunks;
    }

    /**
     * @return list<array{source_type: string, source_ref: ?string, content: string, trust_status: string, effective_date: ?string}>
     */
    private function chunkValidatedCombos(): array
    {
        $chunks = [];

        foreach (ComboPair::where('status', 'validated')->get() as $combo) {
            if (trim((string) $combo->description) === '') {
                continue;
            }

            $chunks[] = [
                'source_type' => KbDocument::SOURCE_COMBO,
                'source_ref' => $combo->id,
                'content' => $combo->description,
                'trust_status' => KbDocument::TRUST_VALIDATED,
                'effective_date' => null,
            ];
        }

        return $chunks;
    }

    /**
     * card_synergy_tags is a plain pivot with no model of its own, so this
     * queries it directly and joins in the card name and tag description to
     * produce meaningful chunk content.
     *
     * @return list<array{source_type: string, source_ref: ?string, content: string, trust_status: string, effective_date: ?string}>
     */
    private function chunkValidatedSynergies(): array
    {
        $rows = DB::table('card_synergy_tags')
            ->join('synergy_tags', 'synergy_tags.id', '=', 'card_synergy_tags.synergy_tag_id')
            ->join('cards', 'cards.id', '=', 'card_synergy_tags.card_id')
            ->where('card_synergy_tags.status', 'validated')
            ->select([
                'cards.id as card_id',
                'cards.name as card_name',
                'synergy_tags.id as synergy_tag_id',
                'synergy_tags.name as tag_name',
                'synergy_tags.description as tag_description',
            ])
            ->get();

        $chunks = [];

        foreach ($rows as $row) {
            $content = trim(collect([$row->card_name.' — '.$row->tag_name, $row->tag_description])->filter()->implode('. '));

            if ($content === '') {
                continue;
            }

            $chunks[] = [
                'source_type' => KbDocument::SOURCE_SYNERGY,
                'source_ref' => "{$row->card_id}:{$row->synergy_tag_id}",
                'content' => $content,
                'trust_status' => KbDocument::TRUST_VALIDATED,
                'effective_date' => null,
            ];
        }

        return $chunks;
    }

    /**
     * Merges any section shorter than enrichment.embedding.min_chunk_chars
     * into the section that follows it, so trivial single-line sections
     * don't produce near-empty chunks. A trailing run of small sections with
     * nothing after it merges into the previous emitted chunk instead.
     *
     * @param  array<string, string>  $sections
     * @return array<string, string>
     */
    private function mergeSmallSections(array $sections): array
    {
        $minChars = (int) config('enrichment.embedding.min_chunk_chars');
        $merged = [];
        $carryContent = null;

        foreach ($sections as $key => $content) {
            if ($carryContent !== null) {
                $content = $carryContent."\n".$content;
                $carryContent = null;
            }

            if (mb_strlen($content) < $minChars) {
                $carryContent = $content;

                continue;
            }

            $merged[$key] = $content;
        }

        if ($carryContent !== null) {
            if ($merged !== []) {
                $lastKey = array_key_last($merged);
                $merged[$lastKey] .= "\n".$carryContent;
            } else {
                $merged[array_key_last($sections)] = $carryContent;
            }
        }

        return $merged;
    }

    /**
     * Splits a chunk exceeding enrichment.embedding.max_chunk_chars into
     * multiple pieces, suffixing the source_ref with a 1-based index.
     *
     * @return array<string, string>
     */
    private function splitLarge(string $sourceRef, string $content): array
    {
        $maxChars = (int) config('enrichment.embedding.max_chunk_chars');

        if (mb_strlen($content) <= $maxChars) {
            return [$sourceRef => $content];
        }

        $pieces = [];

        foreach (mb_str_split($content, $maxChars) as $index => $piece) {
            $pieces[$sourceRef.'#'.($index + 1)] = $piece;
        }

        return $pieces;
    }
}
