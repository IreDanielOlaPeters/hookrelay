<?php

namespace App\Services\Delivery;

use Illuminate\Support\Facades\Redis;

/**
 * Per-destination circuit breaker kept in Redis so every worker shares it.
 *
 *  closed    -> requests flow; consecutive failures are counted.
 *  open      -> after `failureThreshold` consecutive failures; requests are skipped
 *               (rescheduled, not counted as attempts) for `openSeconds`.
 *  half_open -> once the open period ends, exactly one probe request is let through
 *               (SET NX). Success closes the circuit; failure re-opens it for another period.
 */
class CircuitBreaker
{
    public const CLOSED = 'closed';

    public const OPEN = 'open';

    public const HALF_OPEN = 'half_open';

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $openSeconds = 60,
    ) {}

    /**
     * Ask permission to call the destination.
     * Returns null when allowed, or the unix timestamp to retry at when the circuit is open.
     */
    public function acquire(string $destinationId, ?int $now = null): ?int
    {
        $now ??= time();
        $openUntil = $this->openUntil($destinationId);

        if ($openUntil === null) {
            return null; // closed
        }
        if ($now < $openUntil) {
            return $openUntil; // open
        }

        // Half-open: only one probe at a time. The probe lock expires on its own
        // in case the worker holding it dies mid-request.
        $gotProbe = $this->redis()->set($this->key($destinationId, 'probe'), (string) $now, 'EX', $this->openSeconds, 'NX');

        return $gotProbe ? null : $now + max(1, intdiv($this->openSeconds, 6));
    }

    public function recordSuccess(string $destinationId): void
    {
        $this->redis()->del(
            $this->key($destinationId, 'failures'),
            $this->key($destinationId, 'open_until'),
            $this->key($destinationId, 'probe'),
        );
    }

    /** @return bool true when this failure opened the circuit (closed -> open, or a failed probe) */
    public function recordFailure(string $destinationId, ?int $now = null): bool
    {
        $now ??= time();

        $openUntil = $this->openUntil($destinationId);
        if ($openUntil !== null) {
            // A failed half-open probe re-opens the circuit; a straggler from before it opened doesn't count.
            if ($now >= $openUntil) {
                $this->open($destinationId, $now);

                return true;
            }

            return false;
        }

        $failures = (int) $this->redis()->incr($this->key($destinationId, 'failures'));
        if ($failures === $this->failureThreshold) {
            $this->open($destinationId, $now);

            return true;
        }

        return false;
    }

    /** @return array{state: string, consecutive_failures: int, open_until: int|null} */
    public function status(string $destinationId, ?int $now = null): array
    {
        $now ??= time();
        $openUntil = $this->openUntil($destinationId);

        return [
            'state' => match (true) {
                $openUntil === null => self::CLOSED,
                $now < $openUntil => self::OPEN,
                default => self::HALF_OPEN,
            },
            'consecutive_failures' => (int) $this->redis()->get($this->key($destinationId, 'failures')),
            'open_until' => $openUntil,
        ];
    }

    public function reset(string $destinationId): void
    {
        $this->recordSuccess($destinationId);
    }

    private function open(string $destinationId, int $now): void
    {
        $this->redis()->set($this->key($destinationId, 'open_until'), (string) ($now + $this->openSeconds));
        $this->redis()->del($this->key($destinationId, 'probe'));
    }

    private function openUntil(string $destinationId): ?int
    {
        $value = $this->redis()->get($this->key($destinationId, 'open_until'));

        return $value === null || $value === false ? null : (int) $value;
    }

    private function key(string $destinationId, string $suffix): string
    {
        return "relay:circuit:{$destinationId}:{$suffix}";
    }

    private function redis()
    {
        return Redis::connection();
    }
}
