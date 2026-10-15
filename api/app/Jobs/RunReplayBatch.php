<?php

namespace App\Jobs;

use App\Models\ReplayBatch;
use App\Services\Delivery\Replayer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Throwable;

/** Bulk replay in the background, chunk by chunk, recording progress on the batch row. */
class RunReplayBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $batchId) {}

    public function handle(Replayer $replayer): void
    {
        $batch = ReplayBatch::find($this->batchId);
        if (! $batch || $batch->status !== ReplayBatch::QUEUED) {
            return;
        }

        $batch->update([
            'status' => ReplayBatch::RUNNING,
            'started_at' => Carbon::now(),
            'total' => $replayer->countMatching($batch->filters),
        ]);

        $replayer->replayMatching($batch->filters, function (int $processed, int $replayed) use ($batch) {
            $batch->update(['processed' => $processed, 'replayed' => $replayed]);
        });

        $batch->update(['status' => ReplayBatch::COMPLETED, 'finished_at' => Carbon::now()]);
    }

    public function failed(?Throwable $e): void
    {
        ReplayBatch::whereKey($this->batchId)->update([
            'status' => ReplayBatch::FAILED,
            'error' => $e ? mb_strimwidth($e->getMessage(), 0, 1000) : 'Unknown error',
            'finished_at' => Carbon::now(),
        ]);
    }
}
