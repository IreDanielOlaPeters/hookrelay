<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListEventsRequest;
use App\Http\Resources\EventResource;
use App\Models\WebhookEvent;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class EventController extends Controller
{
    public function index(ListEventsRequest $request): AnonymousResourceCollection
    {
        $counts = collect(DeliveryStatus::cases())
            ->mapWithKeys(fn (DeliveryStatus $s) => [
                "deliveries as {$s->value}_count" => fn ($q) => $q->where('status', $s->value),
            ])->all();

        $events = WebhookEvent::query()
            ->with('source')
            ->withCount($counts)
            ->when($request->validated('source_id'), fn ($q, $id) => $q->where('source_id', $id))
            ->when($request->validated('from'), fn ($q, $from) => $q->where('received_at', '>=', Carbon::parse($from)))
            ->when($request->validated('to'), fn ($q, $to) => $q->where('received_at', '<=', endOfIfDate($to)))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($request->validated('per_page', 25))
            ->withQueryString();

        return EventResource::collection($events);
    }

    public function show(WebhookEvent $event): EventResource
    {
        $event->load(['source', 'deliveries' => fn ($q) => $q->orderBy('created_at'), 'deliveries.destination', 'deliveries.attempts']);

        return (new EventResource($event))->detailed();
    }
}
