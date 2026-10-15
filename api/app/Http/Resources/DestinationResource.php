<?php

namespace App\Http\Resources;

use App\Models\Destination;
use App\Services\Delivery\CircuitBreaker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin Destination */
class DestinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $circuit = app(CircuitBreaker::class)->status($this->id);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'has_auth_header' => filled($this->auth_header),
            'timeout_ms' => $this->timeout_ms,
            'rate_limit_rps' => $this->rate_limit_rps,
            'max_attempts' => $this->max_attempts,
            'enabled' => $this->enabled,
            'circuit' => [
                'state' => $circuit['state'],
                'consecutive_failures' => $circuit['consecutive_failures'],
                'open_until' => $circuit['open_until'] ? Carbon::createFromTimestamp($circuit['open_until']) : null,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
