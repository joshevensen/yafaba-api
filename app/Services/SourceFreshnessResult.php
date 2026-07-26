<?php

namespace App\Services;

final class SourceFreshnessResult
{
    public function __construct(
        public readonly string $sourceKey,
        public readonly ?string $fingerprint,
        public readonly string $fingerprintType,
        public readonly bool $changed,
        public readonly bool $probeFailed,
        public readonly ?string $error = null,
    ) {}
}
