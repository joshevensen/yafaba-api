<?php

namespace App\Services;

use App\Console\Commands\IngestErrataBulletins;
use App\Models\SourceSyncState;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class SourceFreshnessChecker
{
    /**
     * Probe the given source's upstream, upsert its SourceSyncState row, and
     * return whether it should be treated as changed (i.e. worth ingesting).
     */
    public function check(string $sourceKey): SourceFreshnessResult
    {
        $sourceConfig = config("pipeline.sources.{$sourceKey}");

        if (! is_array($sourceConfig)) {
            throw new InvalidArgumentException("Unknown pipeline source: {$sourceKey}");
        }

        $strategy = $sourceConfig['strategy'];
        $state = SourceSyncState::firstOrNew(['source_key' => $sourceKey]);
        $outcome = $this->probe($strategy, $sourceConfig);

        $state->last_checked_at = now();

        if (! $outcome['success']) {
            $state->last_error = $outcome['error'];
            $state->save();

            return new SourceFreshnessResult(
                sourceKey: $sourceKey,
                fingerprint: $state->fingerprint,
                fingerprintType: $state->fingerprint_type ?? '',
                changed: false,
                probeFailed: true,
                error: $outcome['error'],
            );
        }

        $fingerprintChanged = $state->fingerprint === null || $state->fingerprint !== $outcome['fingerprint'];

        if ($fingerprintChanged) {
            $state->fingerprint = $outcome['fingerprint'];
            $state->fingerprint_type = $outcome['fingerprintType'];
            $state->last_changed_at = now();
        }

        $state->last_error = null;

        $changed = $strategy === 'always'
            || $state->pulled_fingerprint === null
            || $state->fingerprint !== $state->pulled_fingerprint;

        $state->save();

        return new SourceFreshnessResult(
            sourceKey: $sourceKey,
            fingerprint: $state->fingerprint,
            fingerprintType: $state->fingerprint_type,
            changed: $changed,
            probeFailed: false,
        );
    }

    /**
     * Record that the given source has been pulled/ingested at its current
     * fingerprint. Assumes the SourceSyncState row already exists (created by
     * a prior call to check()).
     */
    public function markPulled(string $sourceKey, ?string $fingerprint): void
    {
        $state = SourceSyncState::where('source_key', $sourceKey)->firstOrFail();

        $state->pulled_fingerprint = $fingerprint;
        $state->last_pulled_at = now();
        $state->save();
    }

    /**
     * Dispatch to the probe method for the source's configured strategy.
     *
     * @param  array<string, mixed>  $sourceConfig
     * @return array{success: bool, fingerprint: ?string, fingerprintType: ?string, error: ?string}
     */
    private function probe(string $strategy, array $sourceConfig): array
    {
        return match ($strategy) {
            'github_commit' => $this->probeGithubCommit($sourceConfig),
            'http_fingerprint' => $this->probeHttpFingerprint($sourceConfig),
            'always' => $this->probeAlways(),
            default => $this->failure("Unknown pipeline source strategy: {$strategy}"),
        };
    }

    /**
     * Probe a GitHub repository path for its latest commit sha.
     *
     * @param  array<string, mixed>  $sourceConfig
     * @return array{success: bool, fingerprint: ?string, fingerprintType: ?string, error: ?string}
     */
    private function probeGithubCommit(array $sourceConfig): array
    {
        $apiUrl = config('services.github.api_url');
        $token = config('services.github.token');

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => config('pipeline.user_agent'),
        ];

        if (! empty($token)) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->get("{$apiUrl}/repos/{$sourceConfig['repository']}/commits", [
                    'path' => $sourceConfig['path'],
                    'sha' => $sourceConfig['ref'],
                    'per_page' => 1,
                ]);
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }

        if (! $response->successful()) {
            return $this->failure("GitHub commits request failed with status {$response->status()}");
        }

        $json = $response->json();

        if (! is_array($json) || $json === [] || ! isset($json[0]['sha']) || ! is_string($json[0]['sha'])) {
            return $this->failure('GitHub commits response missing a usable sha');
        }

        return $this->success($json[0]['sha'], SourceSyncState::FINGERPRINT_GIT_SHA);
    }

    /**
     * Probe a plain HTTP resource for freshness via HEAD (ETag/Last-Modified),
     * falling back to a GET content hash when HEAD is unusable.
     *
     * @param  array<string, mixed>  $sourceConfig
     * @return array{success: bool, fingerprint: ?string, fingerprintType: ?string, error: ?string}
     */
    private function probeHttpFingerprint(array $sourceConfig): array
    {
        $url = $sourceConfig['url'];
        $userAgent = $this->resolveUserAgent($sourceConfig);

        try {
            $headResponse = Http::withHeaders(['User-Agent' => $userAgent])->timeout(30)->head($url);
        } catch (Throwable) {
            $headResponse = null;
        }

        if ($headResponse !== null && $headResponse->successful()) {
            $etag = $headResponse->header('ETag');

            if ($etag !== null && $etag !== '') {
                return $this->success(trim($etag, '"'), SourceSyncState::FINGERPRINT_ETAG);
            }

            $lastModified = $headResponse->header('Last-Modified');

            if ($lastModified !== null && $lastModified !== '') {
                return $this->success($lastModified, SourceSyncState::FINGERPRINT_LAST_MODIFIED);
            }
        }

        try {
            $getResponse = Http::withHeaders(['User-Agent' => $userAgent])->timeout(30)->get($url);
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }

        if (! $getResponse->successful()) {
            return $this->failure("HTTP fingerprint GET failed with status {$getResponse->status()}");
        }

        return $this->success(hash('sha256', $getResponse->body()), SourceSyncState::FINGERPRINT_CONTENT_HASH);
    }

    /**
     * The "always" strategy never probes and never fails; it simply signals
     * that the source should always be treated as changed.
     *
     * @return array{success: bool, fingerprint: ?string, fingerprintType: ?string, error: ?string}
     */
    private function probeAlways(): array
    {
        return $this->success(null, SourceSyncState::FINGERPRINT_ALWAYS);
    }

    /**
     * Resolve the User-Agent to send for a source, honoring the 'browser'
     * override some sources require to avoid being rejected upstream.
     *
     * @param  array<string, mixed>  $sourceConfig
     */
    private function resolveUserAgent(array $sourceConfig): string
    {
        if (($sourceConfig['user_agent'] ?? null) === 'browser') {
            return IngestErrataBulletins::USER_AGENT;
        }

        return config('pipeline.user_agent');
    }

    /**
     * @return array{success: bool, fingerprint: ?string, fingerprintType: ?string, error: ?string}
     */
    private function success(?string $fingerprint, string $fingerprintType): array
    {
        return [
            'success' => true,
            'fingerprint' => $fingerprint,
            'fingerprintType' => $fingerprintType,
            'error' => null,
        ];
    }

    /**
     * @return array{success: bool, fingerprint: ?string, fingerprintType: ?string, error: ?string}
     */
    private function failure(string $error): array
    {
        return [
            'success' => false,
            'fingerprint' => null,
            'fingerprintType' => null,
            'error' => $error,
        ];
    }
}
