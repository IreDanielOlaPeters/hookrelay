<?php

namespace App\Providers;

use App\Services\Delivery\Backoff;
use App\Services\Delivery\CircuitBreaker;
use App\Services\Ingest\SignatureVerifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SignatureVerifier::class, fn () => new SignatureVerifier(
            (int) config('relay.stripe_tolerance_seconds', 300),
        ));

        $this->app->singleton(Backoff::class, fn () => new Backoff(
            baseSeconds: config('relay.backoff.base_seconds'),
            factor: config('relay.backoff.factor'),
            capSeconds: config('relay.backoff.cap_seconds'),
        ));

        $this->app->singleton(CircuitBreaker::class, fn () => new CircuitBreaker(
            failureThreshold: config('relay.circuit_breaker.failure_threshold'),
            openSeconds: config('relay.circuit_breaker.open_seconds'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
