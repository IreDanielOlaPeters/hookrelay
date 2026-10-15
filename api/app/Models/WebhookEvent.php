<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An inbound webhook, stored exactly as received.
 * Named WebhookEvent to avoid clashing with Laravel's Event facade; the table is `events`.
 */
class WebhookEvent extends Model
{
    use HasUuids;

    public const ENCODING_UTF8 = 'utf8';

    public const ENCODING_BASE64 = 'base64';

    protected $table = 'events';

    public $timestamps = false;

    protected $fillable = [
        'source_id', 'idempotency_key', 'headers', 'body', 'body_encoding',
        'content_type', 'verified', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'verified' => 'boolean',
            'received_at' => 'immutable_datetime',
        ];
    }

    /** The body as the original bytes, whatever encoding it is stored in. */
    public function rawBody(): string
    {
        return $this->body_encoding === self::ENCODING_BASE64
            ? base64_decode($this->body)
            : $this->body;
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'event_id');
    }
}
