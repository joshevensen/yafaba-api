<?php

namespace App\Console\Commands;

use App\Models\RulesTextVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('data:ingest-rules-text {--url= : Override the upstream Comprehensive Rules text URL}')]
#[Description('Ingest a versioned snapshot of the Comprehensive Rules text into rules_text_versions.')]
class IngestRulesText extends Command
{
    public const SOURCE_URL = 'https://rules.fabtcg.com/txt/latest/en-fab-cr.txt';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $body = $this->fetchText();
        } catch (Throwable $e) {
            $this->error("Failed to fetch rules text: {$e->getMessage()}");

            return Command::FAILURE;
        }

        if (trim($body) === '') {
            $this->error('Upstream rules text was empty or malformed.');

            return Command::FAILURE;
        }

        $previous = RulesTextVersion::query()->orderByDesc('fetched_at')->orderByDesc('id')->first();
        $currentSections = $this->parseSections($body);

        if ($previous === null) {
            $diff = null;
        } else {
            $previousSections = $this->parseSections($previous->full_text);
            $diff = json_encode($this->diffSections($previousSections, $currentSections), JSON_THROW_ON_ERROR);
        }

        RulesTextVersion::create([
            'fetched_at' => now(),
            'full_text' => $body,
            'diff_from_previous' => $diff,
        ]);

        $sectionCount = count($currentSections);

        if ($previous === null) {
            $this->info("Rules text snapshot stored: first snapshot ({$sectionCount} sections).");
        } else {
            $decoded = json_decode($diff, true);
            $added = count($decoded['added']);
            $removed = count($decoded['removed']);
            $changed = count($decoded['changed']);
            $this->info("Rules text snapshot stored: {$added} added, {$removed} removed, {$changed} changed ({$sectionCount} sections).");
        }

        return Command::SUCCESS;
    }

    /**
     * Fetch the upstream rules text body.
     */
    private function fetchText(): string
    {
        $url = $this->option('url') ?: self::SOURCE_URL;

        return Http::timeout(180)->get($url)->throw()->body();
    }

    /**
     * Parse rules text into an ordered map of section-key => section-body.
     *
     * A line matching a numbered rule/glossary pattern (e.g. `1.0.1a`,
     * `1.-1.293`) opens a new section; every other line — chapter headings,
     * prose, examples, blank lines — is appended to the currently-open
     * section's body. Lines before the first section accumulate under the
     * reserved `preamble` key. This map is used only to compute the diff
     * against the previous snapshot; `full_text` always stores the raw body.
     *
     * @return array<string, string>
     */
    private function parseSections(string $text): array
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
     * Diff two section maps by key, returning deterministic sorted lists of
     * added, removed, and changed section keys.
     *
     * @param  array<string, string>  $previous
     * @param  array<string, string>  $current
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}
     */
    private function diffSections(array $previous, array $current): array
    {
        $added = array_keys(array_diff_key($current, $previous));
        $removed = array_keys(array_diff_key($previous, $current));

        $changed = [];
        foreach (array_keys(array_intersect_key($current, $previous)) as $key) {
            if (trim($current[$key]) !== trim($previous[$key])) {
                $changed[] = $key;
            }
        }

        sort($added);
        sort($removed);
        sort($changed);

        return [
            'added' => array_values($added),
            'removed' => array_values($removed),
            'changed' => array_values($changed),
        ];
    }
}
