<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDestinationRequest;
use App\Http\Resources\DestinationResource;
use App\Models\Destination;
use App\Services\Delivery\CircuitBreaker;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DestinationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DestinationResource::collection(Destination::query()->orderBy('name')->get());
    }

    public function store(StoreDestinationRequest $request): DestinationResource
    {
        return new DestinationResource(Destination::create($request->validated()));
    }

    public function show(Destination $destination): DestinationResource
    {
        return new DestinationResource($destination);
    }

    public function update(StoreDestinationRequest $request, Destination $destination): DestinationResource
    {
        $destination->update($request->validated());

        return new DestinationResource($destination);
    }

    public function destroy(Destination $destination, CircuitBreaker $breaker): Response
    {
        $destination->delete();
        $breaker->reset($destination->id);

        return response()->noContent();
    }
}
