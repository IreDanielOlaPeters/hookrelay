<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReplayBatch extends Model
{
    use HasUuids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = ['filters', 'status', 'total', 'processed', 'replayed', 'error', 'created_by', 'started_at', 'finished_at'];

    protected $attributes = ['status' => self::QUEUED, 'total' => 0, 'processed' => 0, 'replayed' => 0];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'total' => 'integer',
            'processed' => 'integer',
            'replayed' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
