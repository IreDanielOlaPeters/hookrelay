<?php

namespace App\Http\Resources;

use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Connection */
class ConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_id' => $this->source_id,
            'destination_id' => $this->destination_id,
            'enabled' => $this->enabled,
            'source' => $this->whenLoaded('source', fn () => $this->source->only(['id', 'name', 'slug'])),
            'destination' => $this->whenLoaded('destination', fn () => $this->destination->only(['id', 'name'])),
            'created_at' => $this->created_at,
        ];
    }
}
