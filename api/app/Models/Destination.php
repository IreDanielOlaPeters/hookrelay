<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'url', 'auth_header', 'timeout_ms', 'rate_limit_rps', 'max_attempts', 'enabled'];

    protected $hidden = ['auth_header'];

    protected $attributes = [
        'timeout_ms' => 10000,
        'max_attempts' => 6,
        'enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'auth_header' => 'encrypted',
            'timeout_ms' => 'integer',
            'rate_limit_rps' => 'integer',
            'max_attempts' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
