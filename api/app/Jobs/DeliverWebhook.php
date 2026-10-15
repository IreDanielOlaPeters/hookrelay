<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Services\Delivery\Deliverer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Thin queue wrapper around Deliverer. Retry policy for the *webhook* lives in the
 * deliveries table (attempt_count, next_attempt_at), not in the queue: each job is one try.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Rate-limit releases must not exhaust the job, so queue-level tries are unlimited... */
    public int $tries = 0;

    /** ...but unexpected exceptions (DB down, bug) give up after a few and hand back to the outbox. */
    public int $maxExceptions = 3;

    /** Must exceed the max destination timeout (60 s). */
    public int $timeout = 90;

    public function __construct(public readonly string $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(Deliverer $deliverer): void
    {
        $releaseAfter = $deliverer->deliver($this->deliveryId);

        if ($releaseAfter !== null) {
            $this->release($releaseAfter);
        }
    }

    public function failed(?Throwable $e): void
    {
        // Return the delivery to the outbox; the dispatcher will pick it up again.
        Delivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', DeliveryStatus::Delivering->value)
            ->update([
                'status' => DeliveryStatus::Pending->value,
                'next_attempt_at' => Carbon::now()->addMinute(),
                'updated_at' => Carbon::now(),
            ]);
    }
}
