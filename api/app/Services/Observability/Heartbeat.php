<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness for processes without an HTTP server (workers, dispatcher, cron): each loop writes
 * a timestamp to Redis, and the container healthcheck (`relay:health --role=…`) checks it's fresh.
 */
class Heartbeat
{
    private static array $lastBeat = [];

    public static function key(string $role, ?string $host = null): string
    {
        return "relay:heartbeat:{$role}:".($host ?? gethostname());
    }

    /** Record a beat, at most every $everySeconds per process to keep Redis traffic negligible. */
    public static function beat(string $role, int $everySeconds = 5): void
    {
        $now = now()->getTimestamp();
        if (($now - (self::$lastBeat[$role] ?? 0)) < $everySeconds) {
            return;
        }
        self::$lastBeat[$role] = $now;
        try {
            Redis::connection()->set(self::key($role), (string) $now, 'EX', 3600);
        } catch (Throwable) {
            // Redis down: the healthcheck will report it.
        }
    }

    /** Seconds since the last beat of this host's process, or null if none. */
    public static function age(string $role, ?string $host = null): ?int
    {
        $at = Redis::connection()->get(self::key($role, $host));

        return $at === null || $at === false ? null : now()->getTimestamp() - (int) $at;
    }

    public static function reset(): void
    {
        self::$lastBeat = [];
    }
}
