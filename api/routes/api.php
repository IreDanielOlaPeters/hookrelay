<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\DestinationController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ReplayBatchController;
use App\Http\Controllers\Api\SourceController;
use App\Http\Controllers\Api\StatsController;
use Illuminate\Support\Facades\Route;

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
Route::patterns(['replayBatch' => $uuid, 'source' => $uuid, 'destination' => $uuid, 'event' => $uuid, 'delivery' => $uuid, 'connection' => '[0-9]+']);

// Public: login and the demo credentials hint (only populated when DEMO_MODE=true).
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('auth/demo', [AuthController::class, 'demo']);

// TODO(multi-tenancy): scope every query below to the authenticated tenant.
Route::middleware('relay.auth')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Read access and the actions the demo user may take: browse, send test webhooks,
    // replay, and create sources/connections/destinations (demo: mock receiver only).
    Route::get('sources', [SourceController::class, 'index']);
    Route::post('sources', [SourceController::class, 'store']);
    Route::get('sources/{source}', [SourceController::class, 'show']);
    Route::post('sources/{source}/test', [SourceController::class, 'test']);
    Route::get('destinations', [DestinationController::class, 'index']);
    Route::post('destinations', [DestinationController::class, 'store']);
    Route::get('destinations/{destination}', [DestinationController::class, 'show']);
    Route::get('connections', [ConnectionController::class, 'index']);
    Route::post('connections', [ConnectionController::class, 'store']);

    Route::get('events', [EventController::class, 'index']);
    Route::get('events/{event}', [EventController::class, 'show']);

    Route::get('deliveries', [DeliveryController::class, 'index']);
    Route::post('deliveries/replay', [DeliveryController::class, 'bulkReplay']);
    Route::get('deliveries/{delivery}', [DeliveryController::class, 'show']);
    Route::post('deliveries/{delivery}/replay', [DeliveryController::class, 'replay']);

    Route::get('replay-batches', [ReplayBatchController::class, 'index']);
    Route::get('replay-batches/{replayBatch}', [ReplayBatchController::class, 'show']);

    Route::get('stats', StatsController::class);

    // Changing settings or deleting data: admins only.
    Route::middleware('relay.admin')->group(function () {
        Route::match(['put', 'patch'], 'sources/{source}', [SourceController::class, 'update']);
        Route::delete('sources/{source}', [SourceController::class, 'destroy']);
        Route::match(['put', 'patch'], 'destinations/{destination}', [DestinationController::class, 'update']);
        Route::delete('destinations/{destination}', [DestinationController::class, 'destroy']);
        Route::match(['put', 'patch'], 'connections/{connection}', [ConnectionController::class, 'update']);
        Route::delete('connections/{connection}', [ConnectionController::class, 'destroy']);
    });
});
