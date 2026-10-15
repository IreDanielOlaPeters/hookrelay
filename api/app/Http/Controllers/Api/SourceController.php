<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSourceRequest;
use App\Http\Resources\SourceResource;
use App\Models\Source;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SourceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SourceResource::collection(Source::query()->orderBy('name')->get());
    }

    public function store(StoreSourceRequest $request): SourceResource
    {
        return new SourceResource(Source::create($request->validated()));
    }

    public function show(Source $source): SourceResource
    {
        return new SourceResource($source);
    }

    public function update(StoreSourceRequest $request, Source $source): SourceResource
    {
        $source->update($request->validated());

        return new SourceResource($source);
    }

    public function destroy(Source $source): Response
    {
        $source->delete();

        return response()->noContent();
    }
}
