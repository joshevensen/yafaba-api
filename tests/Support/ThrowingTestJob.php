<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * A minimal job that always throws, used to exercise real Bus::chain()
 * ->catch() dispatch mechanics in tests without depending on any
 * application job actually failing.
 */
class ThrowingTestJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}
