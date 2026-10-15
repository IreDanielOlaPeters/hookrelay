<?php

namespace App\Http\Resources;

use App\Models\ReplayBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReplayBatch */
class ReplayBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'filters' => $this->filters,
            'total' => $this->total,
            'processed' => $this->processed,
            'replayed' => $this->replayed,
            'progress' => $this->total > 0 ? round($this->processed / $this->total, 4) : ($this->status === 'completed' ? 1.0 : 0.0),
            'error' => $this->error,
            'created_at' => $this->created_at,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
        ];
    }
}
