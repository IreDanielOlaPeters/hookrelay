<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConnectionRequest;
use App\Http\Resources\ConnectionResource;
use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ConnectionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['source_id' => ['sometimes', 'uuid']]);

        $connections = Connection::query()
            ->with(['source', 'destination'])
            ->when($request->query('source_id'), fn ($q, $id) => $q->where('source_id', $id))
            ->orderBy('id')
            ->get();

        return ConnectionResource::collection($connections);
    }

    public function store(StoreConnectionRequest $request): ConnectionResource
    {
        $connection = Connection::create($request->validated());

        return new ConnectionResource($connection->load(['source', 'destination']));
    }

    public function update(StoreConnectionRequest $request, Connection $connection): ConnectionResource
    {
        $connection->update($request->validated());

        return new ConnectionResource($connection->load(['source', 'destination']));
    }

    public function destroy(Connection $connection): Response
    {
        $connection->delete();

        return response()->noContent();
    }
}
