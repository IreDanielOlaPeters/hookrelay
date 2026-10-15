<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkReplayRequest;
use App\Http\Requests\ListDeliveriesRequest;
use App\Http\Resources\DeliveryResource;
use App\Http\Resources\ReplayBatchResource;
use App\Jobs\RunReplayBatch;
use App\Models\Delivery;
use App\Models\ReplayBatch;
use App\Services\Delivery\DeliveryInFlight;
use App\Services\Delivery\Replayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class DeliveryController extends Controller
{
    public function index(ListDeliveriesRequest $request): AnonymousResourceCollection
    {
        $deliveries = Delivery::query()
            ->with(['destination', 'event'])
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->validated('destination_id'), fn ($q, $id) => $q->where('destination_id', $id))
            ->when($request->validated('event_id'), fn ($q, $id) => $q->where('event_id', $id))
            ->when($request->boolean('has_error'), fn ($q) => $q->whereNotNull('last_error'))
            ->when($request->validated('from'), fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)))
            ->when($request->validated('to'), fn ($q, $to) => $q->where('created_at', '<=', endOfIfDate($to)))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($request->validated('per_page', 25))
            ->withQueryString();

        return DeliveryResource::collection($deliveries);
    }

    public function show(Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($delivery->load(['destination', 'event', 'attempts']));
    }

    public function replay(Delivery $delivery, Replayer $replayer): DeliveryResource|JsonResponse
    {
        try {
            $delivery = $replayer->replay($delivery);
        } catch (DeliveryInFlight $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new DeliveryResource($delivery->load(['destination', 'event', 'attempts']));
    }

    /** Queue a bulk replay; poll GET /api/replay-batches/{id} for progress. */
    public function bulkReplay(BulkReplayRequest $request): JsonResponse
    {
        $user = $request->user();
        $batch = ReplayBatch::create([
            'filters' => array_filter($request->validated(), fn ($v) => $v !== null && $v !== '') + ['status' => 'dead'],
            'created_by' => $user?->exists ? $user->id : null,
        ]);
        RunReplayBatch::dispatch($batch->id)->onQueue('default');

        return (new ReplayBatchResource($batch->refresh()))->response()->setStatusCode(202);
    }
}
