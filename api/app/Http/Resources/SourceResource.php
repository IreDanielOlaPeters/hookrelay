<?php

namespace App\Http\Resources;

use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Source */
class SourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'provider' => $this->provider,
            'has_signing_secret' => filled($this->signing_secret),
            'dedupe_window_seconds' => $this->dedupe_window_seconds,
            'rate_limit_rps' => $this->rate_limit_rps,
            'ingest_url' => rtrim(config('app.url'), '/').'/in/'.$this->slug,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
