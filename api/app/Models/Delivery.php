<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use HasUuids;

    protected $fillable = ['event_id', 'destination_id', 'status', 'attempt_count', 'next_attempt_at', 'last_error'];

    protected $attributes = [
        'status' => 'pending',
        'attempt_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempt_count' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'event_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class)->orderBy('started_at')->orderBy('id');
    }
}
