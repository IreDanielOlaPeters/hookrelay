<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Process-shared Prometheus counters and histograms, kept in Redis so every API and worker
 * container contributes to the same series. Gauges (deliveries by status, queue depth,
 * breaker state) are computed at scrape time by MetricsController instead.
 *
 * Recording never throws: metrics must not break ingestion or delivery.
 */
class Metrics
{
    /** Attempt latency buckets, in seconds. */
    public const BUCKETS = [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30];

    public const EVENTS_KEY = 'relay:metrics:events_received';

    public const ATTEMPTS_KEY = 'relay:metrics:attempts';

    public const HISTOGRAM_KEY = 'relay:metrics:attempt_duration';

    public const ALERTS_KEY = 'relay:metrics:alerts';

    public function eventReceived(string $sourceSlug): void
    {
        $this->safely(fn () => Redis::connection()->hincrby(self::EVENTS_KEY, $sourceSlug, 1));
    }

    /** @param  string  $outcome  success|retry|failed|dead */
    public function attempt(string $outcome, float $seconds): void
    {
        $this->safely(function () use ($outcome, $seconds) {
            $redis = Redis::connection();
            $redis->hincrby(self::ATTEMPTS_KEY, $outcome, 1);
            foreach (self::BUCKETS as $le) {
                if ($seconds <= $le) {
                    $redis->hincrby(self::HISTOGRAM_KEY, "bucket:{$le}", 1);
                }
            }
            $redis->hincrby(self::HISTOGRAM_KEY, 'count', 1);
            $redis->hincrbyfloat(self::HISTOGRAM_KEY, 'sum', $seconds);
        });
    }

    /** @param  string  $result  sent|suppressed */
    public function alert(string $kind, string $result): void
    {
        $this->safely(fn () => Redis::connection()->hincrby(self::ALERTS_KEY, "{$kind}:{$result}", 1));
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
