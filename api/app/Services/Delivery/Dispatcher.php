<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryStatus;
use App\Jobs\DeliverWebhook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves due deliveries from the Postgres outbox onto the Redis queue.
 *
 * Claiming uses `FOR UPDATE SKIP LOCKED`, so any number of dispatchers can run at once:
 * each row is claimed (pending -> delivering) by exactly one of them.
 */
class Dispatcher
{
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * Atomically claim up to $limit due deliveries and return their ids.
     * Deliveries for disabled destinations stay pending until the destination is re-enabled.
     *
     * @return list<string>
     */
    public function claim(int $limit, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        // The CTE runs exactly once. (An `IN (SELECT ... LIMIT ... SKIP LOCKED)` subquery can be
        // re-executed by the planner and would ignore the limit.)
        $rows = DB::connection($this->connection)->select(<<<'SQL'
            WITH due AS (
                SELECT p.id
                  FROM deliveries AS p
                  JOIN destinations AS dst ON dst.id = p.destination_id AND dst.enabled = true
                 WHERE p.status = ?
                   AND p.next_attempt_at <= ?
              ORDER BY p.next_attempt_at
                 LIMIT ?
                   FOR UPDATE OF p SKIP LOCKED
            )
            UPDATE deliveries AS d
               SET status = ?, updated_at = ?
              FROM due
             WHERE d.id = due.id
         RETURNING d.id
        SQL, [
            DeliveryStatus::Pending->value, $now, $limit,
            DeliveryStatus::Delivering->value, $now,
        ]);

        return array_map(fn ($row) => $row->id, $rows);
    }

    /** Claim due deliveries and push one DeliverWebhook job per delivery. Returns how many. */
    public function dispatchDue(int $limit, ?Carbon $now = null): int
    {
        $ids = $this->claim($limit, $now);

        // If pushing fails after the claim committed, the rows sit in `delivering`
        // until recoverStuck() puts them back. At-least-once, never lost.
        foreach ($ids as $id) {
            DeliverWebhook::dispatch($id)->onQueue(config('relay.dispatcher.queue'));
        }

        return count($ids);
    }

    /** Put deliveries stuck in `delivering` (crashed worker, lost job) back to `pending`. */
    public function recoverStuck(int $olderThanSeconds, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        return DB::connection($this->connection)->table('deliveries')
            ->where('status', DeliveryStatus::Delivering->value)
            ->where('updated_at', '<', $now->copy()->subSeconds($olderThanSeconds))
            ->update([
                'status' => DeliveryStatus::Pending->value,
                'next_attempt_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
