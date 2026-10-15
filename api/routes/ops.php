<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

// Operational endpoints: no session, no auth (except the optional RELAY_METRICS_TOKEN).
Route::get('/health', [HealthController::class, 'health']);
Route::get('/ready', [HealthController::class, 'ready']);
Route::get('/metrics', MetricsController::class);
