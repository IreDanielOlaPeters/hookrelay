<?php

use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\DestinationController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\SourceController;
use App\Http\Controllers\Api\StatsController;
use Illuminate\Support\Facades\Route;

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
Route::patterns(['source' => $uuid, 'destination' => $uuid, 'event' => $uuid, 'delivery' => $uuid, 'connection' => '[0-9]+']);

// TODO(multi-tenancy): scope every query below to the authenticated tenant
// once auth moves beyond the single static ADMIN_TOKEN.
Route::middleware('admin')->group(function () {
    Route::apiResource('sources', SourceController::class);
    Route::apiResource('destinations', DestinationController::class);
    Route::apiResource('connections', ConnectionController::class)->except('show');

    Route::get('events', [EventController::class, 'index']);
    Route::get('events/{event}', [EventController::class, 'show']);

    Route::get('deliveries', [DeliveryController::class, 'index']);
    Route::post('deliveries/replay', [DeliveryController::class, 'bulkReplay']);
    Route::get('deliveries/{delivery}', [DeliveryController::class, 'show']);
    Route::post('deliveries/{delivery}/replay', [DeliveryController::class, 'replay']);

    Route::get('stats', StatsController::class);
});
