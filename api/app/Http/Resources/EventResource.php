<?php

namespace App\Http\Resources;

use App\Enums\DeliveryStatus;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WebhookEvent */
class EventResource extends JsonResource
{
    /** Set to include headers, body and deliveries (detail view). */
    public bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'source_id' => $this->source_id,
            'source' => $this->whenLoaded('source', fn () => $this->source->only(['id', 'name', 'slug', 'provider'])),
            'idempotency_key' => $this->idempotency_key,
            'content_type' => $this->content_type,
            'verified' => $this->verified,
            'received_at' => $this->received_at,
            'body_size' => strlen($this->rawBody()),
            'delivery_counts' => $this->deliveryCounts(),
        ];

        if ($this->detailed) {
            $data['headers'] = $this->headers;
            $data['body'] = $this->body;
            $data['body_encoding'] = $this->body_encoding;
            $data['deliveries'] = DeliveryResource::collection($this->whenLoaded('deliveries'));
        }

        return $data;
    }

    private function deliveryCounts(): array
    {
        $counts = array_fill_keys(array_column(DeliveryStatus::cases(), 'value'), 0);
        foreach (DeliveryStatus::cases() as $status) {
            $attribute = $status->value.'_count';
            if (isset($this->resource->{$attribute})) {
                $counts[$status->value] = (int) $this->resource->{$attribute};
            }
        }
        if ($this->relationLoaded('deliveries')) {
            $counts = array_fill_keys(array_keys($counts), 0);
            foreach ($this->deliveries as $delivery) {
                $counts[$delivery->status->value]++;
            }
        }

        return $counts;
    }
}
