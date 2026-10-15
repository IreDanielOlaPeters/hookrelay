<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attempt extends Model
{
    public $timestamps = false;

    protected $fillable = ['delivery_id', 'started_at', 'duration_ms', 'response_status', 'response_body', 'error'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'response_status' => 'integer',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
