<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Database\Eloquent\Builder;
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
     * Replay every delivery matching the filters, in chunks so a big dead-letter queue doesn't
     * hold one giant lock. Runs inside the RunReplayBatch job; $progress gets running totals.
     *
     * @param  array{status?: string, destination_id?: string, from?: string, to?: string}  $filters
     * @param  null|callable(int $processed, int $replayed): void  $progress
     */
    public function replayMatching(array $filters, ?callable $progress = null): int
    {
        $status = $filters['status'] ?? DeliveryStatus::Dead->value;

        $processed = 0;
        $replayed = 0;
        $this->matching($filters)->select('id')->chunkById(self::CHUNK, function ($chunk) use (&$processed, &$replayed, $status, $progress) {
            $replayed += DB::table('deliveries')
                ->whereIn('id', $chunk->pluck('id'))
                ->where('status', $status) // may have changed since the chunk was read
                ->update($this->resetColumns());
            $processed += $chunk->count();
            if ($progress) {
                $progress($processed, $replayed);
            }
        });

        return $replayed;
    }

    public function countMatching(array $filters): int
    {
        return $this->matching($filters)->count();
    }

    private function matching(array $filters): Builder
    {
        return Delivery::query()
            ->where('status', $filters['status'] ?? DeliveryStatus::Dead->value)
            ->when($filters['destination_id'] ?? null, fn ($q, $id) => $q->where('destination_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', endOfIfDate($to)));
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
