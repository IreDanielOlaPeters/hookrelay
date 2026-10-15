<?php

namespace App\Providers;

use App\Services\Delivery\Backoff;
use App\Services\Delivery\CircuitBreaker;
use App\Services\Ingest\SignatureVerifier;
use App\Services\Observability\Heartbeat;
use App\Services\Security\DnsResolver;
use App\Services\Security\UrlGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->app->bind(UrlGuard::class, fn ($app) => new UrlGuard(
            $app->make(DnsResolver::class),
            config('relay.ssrf_allowlist', []),
        ));

        $this->app->singleton(CircuitBreaker::class, fn () => new CircuitBreaker(
            failureThreshold: config('relay.circuit_breaker.failure_threshold'),
            openSeconds: config('relay.circuit_breaker.open_seconds'),
        ));
    }

    public function boot(): void
    {
        // Queue workers beat on every loop (throttled), for the worker container healthcheck.
        Queue::looping(fn () => Heartbeat::beat('worker'));

        // Brute-force protection for dashboard logins: per email and per client IP.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('login-email:'.strtolower((string) $request->input('email'))),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);
    }
}
