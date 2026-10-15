<?php

namespace App\Http\Resources;

use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Delivery */
class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'destination_id' => $this->destination_id,
            'destination' => $this->whenLoaded('destination', fn () => $this->destination->only(['id', 'name', 'url'])),
            'event' => $this->whenLoaded('event', fn () => [
                'id' => $this->event->id,
                'source_id' => $this->event->source_id,
                'received_at' => $this->event->received_at,
            ]),
            'status' => $this->status,
            'attempt_count' => $this->attempt_count,
            'max_attempts' => $this->whenLoaded('destination', fn () => $this->destination->max_attempts),
            'next_attempt_at' => $this->next_attempt_at,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'attempts' => AttemptResource::collection($this->whenLoaded('attempts')),
        ];
    }
}
