<?php

namespace App\Models;

use App\Enums\Provider;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'slug', 'provider', 'signing_secret', 'dedupe_window_seconds'];

    protected $hidden = ['signing_secret'];

    protected $attributes = [
        'provider' => 'generic',
        'dedupe_window_seconds' => 86400,
    ];

    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'signing_secret' => 'encrypted',
            'dedupe_window_seconds' => 'integer',
        ];
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(Destination::class, 'connections')
            ->withPivot('enabled')
            ->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }
}
