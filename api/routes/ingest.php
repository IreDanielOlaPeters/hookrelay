<?php

use App\Http\Controllers\IngestController;
use Illuminate\Support\Facades\Route;

Route::post('/in/{slug}', IngestController::class)
    ->where('slug', '[A-Za-z0-9_-]+')
    ->name('ingest');
