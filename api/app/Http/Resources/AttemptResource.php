<?php

namespace App\Http\Resources;

use App\Models\Attempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attempt */
class AttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'started_at' => $this->started_at,
            'duration_ms' => $this->duration_ms,
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'error' => $this->error,
        ];
    }
}
