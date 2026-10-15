<?php

namespace App\Services\Alerts;

use App\Jobs\SendAlert;
use App\Services\Observability\Metrics;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Operational alerts (a delivery went dead, a circuit breaker opened), sent to Slack and/or
 * email in a queued job. Each alert key fires at most once per cooldown window, so an outage
 * produces one message per destination, not one per webhook.
 */
class Alerter
{
    public function __construct(private readonly Metrics $metrics) {}

    /**
     * @param  string  $kind  delivery_dead|circuit_open
     * @param  string  $key  dedupe key within the kind (usually the destination id)
     * @param  array<string, string|int|null>  $fields
     */
    public function notify(string $kind, string $key, string $title, array $fields = []): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $cooldown = max(1, (int) config('relay.alerts.cooldown_seconds'));
        $first = (bool) Redis::connection()->set("relay:alert:{$kind}:{$key}", (string) time(), 'EX', $cooldown, 'NX');
        if (! $first) {
            $this->metrics->alert($kind, 'suppressed');

            return false;
        }

        $this->metrics->alert($kind, 'sent');
        Log::warning("alert.{$kind}", ['title' => $title] + $fields);
        SendAlert::dispatch($title, $fields)->onQueue('default');

        return true;
    }

    public function configured(): bool
    {
        return filled(config('relay.alerts.slack_webhook_url')) || filled(config('relay.alerts.email'));
    }
}
