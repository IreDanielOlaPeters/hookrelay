<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReplayBatchResource;
use App\Models\ReplayBatch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReplayBatchController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ReplayBatchResource::collection(ReplayBatch::query()->latest()->limit(20)->get());
    }

    public function show(ReplayBatch $replayBatch): ReplayBatchResource
    {
        return new ReplayBatchResource($replayBatch);
    }
}
