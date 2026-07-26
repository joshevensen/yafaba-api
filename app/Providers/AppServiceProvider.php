<?php

namespace App\Providers;

use App\Services\Llm\LlmTransport;
use App\Services\Llm\LlmTransportFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LlmTransport::class, fn ($app) => $app->make(LlmTransportFactory::class)->make((string) config('enrichment.transport')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('enrichment-llm', fn () => Limit::perMinute((int) config('enrichment.rate_limits.llm.allow')));
        RateLimiter::for('enrichment-embedding', fn () => Limit::perMinute((int) config('enrichment.rate_limits.embedding.allow')));
    }
}
