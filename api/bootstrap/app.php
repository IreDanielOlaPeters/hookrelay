<?php

use App\Http\Middleware\AdminToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Ingestion is deliberately outside the `api` and `web` groups:
            // no sessions, no CSRF, no auth, minimal middleware. It has to stay fast.
            Route::middleware([])->group(base_path('routes/ingest.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['admin' => AdminToken::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*', 'in/*'));
    })->create();
