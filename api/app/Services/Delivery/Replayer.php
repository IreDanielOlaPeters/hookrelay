<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Replay puts deliveries back in the outbox with a fresh attempt budget.
 * Previous attempts stay in the `attempts` table as history.
 */
class Replayer
{
    public const CHUNK = 500;

    /** @throws DeliveryInFlight */
    public function replay(Delivery $delivery): Delivery
    {
        $updated = Delivery::query()
            ->whereKey($delivery->id)
            ->where('status', '!=', DeliveryStatus::Delivering->value)
            ->update($this->resetColumns());

        if ($updated === 0) {
            throw new DeliveryInFlight;
        }

        return $delivery->refresh();
    }

    /**
     * Replay every delivery matching the filters, in chunks so a big dead-letter queue
     * does not hold one giant lock.
     * TODO(scale): for very large replays, run this as a queued job and report progress.
     *
     * @param  array{status?: string, destination_id?: string, from?: string, to?: string}  $filters
     */
    public function replayMatching(array $filters): int
    {
        $status = $filters['status'] ?? DeliveryStatus::Dead->value;

        $query = Delivery::query()
            ->where('status', $status)
            ->when($filters['destination_id'] ?? null, fn ($q, $id) => $q->where('destination_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', endOfIfDate($to)));

        $total = 0;
        $query->select('id')->chunkById(self::CHUNK, function ($chunk) use (&$total, $status) {
            $total += DB::table('deliveries')
                ->whereIn('id', $chunk->pluck('id'))
                ->where('status', $status) // may have changed since the chunk was read
                ->update($this->resetColumns());
        });

        return $total;
    }

    private function resetColumns(): array
    {
        $now = Carbon::now();

        return [
            'status' => DeliveryStatus::Pending->value,
            'attempt_count' => 0,
            'next_attempt_at' => $now,
            'last_error' => null,
            'updated_at' => $now,
        ];
    }
}
