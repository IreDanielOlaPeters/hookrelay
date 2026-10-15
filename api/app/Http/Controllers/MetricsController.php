<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryStatus;
use App\Models\Destination;
use App\Services\Delivery\CircuitBreaker;
use App\Services\Observability\Metrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/** GET /metrics in the Prometheus text exposition format (version 0.0.4). */
class MetricsController extends Controller
{
    private array $lines = [];

    public function __invoke(Request $request, CircuitBreaker $breaker): Response
    {
        $token = (string) config('relay.metrics_token');
        if ($token !== '' && ! hash_equals($token, (string) $request->bearerToken())) {
            return response('Unauthorized', 401);
        }

        $redis = Redis::connection();

        $this->family('hookrelay_events_received_total', 'counter', 'Webhooks accepted (excluding duplicates), by source.');
        foreach ($redis->hgetall(Metrics::EVENTS_KEY) ?: [] as $source => $n) {
            $this->sample('hookrelay_events_received_total', ['source' => $source], $n);
        }

        $this->family('hookrelay_deliveries', 'gauge', 'Deliveries currently in each status.');
        $byStatus = DB::table('deliveries')->groupBy('status')->selectRaw('status, count(*) AS n')->pluck('n', 'status');
        foreach (DeliveryStatus::cases() as $status) {
            $this->sample('hookrelay_deliveries', ['status' => $status->value], $byStatus[$status->value] ?? 0);
        }

        $this->family('hookrelay_outbox_due', 'gauge', 'Pending deliveries whose next attempt is due (waiting for the dispatcher).');
        $this->sample('hookrelay_outbox_due', [], DB::table('deliveries')->where('status', 'pending')->where('next_attempt_at', '<=', now())->count());

        $this->family('hookrelay_delivery_attempts_total', 'counter', 'Delivery attempts by outcome (success, retry, failed, dead).');
        foreach ($redis->hgetall(Metrics::ATTEMPTS_KEY) ?: [] as $outcome => $n) {
            $this->sample('hookrelay_delivery_attempts_total', ['outcome' => $outcome], $n);
        }

        $this->family('hookrelay_attempt_duration_seconds', 'histogram', 'HTTP round-trip time of delivery attempts.');
        $h = $redis->hgetall(Metrics::HISTOGRAM_KEY) ?: [];
        foreach (Metrics::BUCKETS as $le) {
            $this->sample('hookrelay_attempt_duration_seconds_bucket', ['le' => (string) $le], $h["bucket:{$le}"] ?? 0);
        }
        $this->sample('hookrelay_attempt_duration_seconds_bucket', ['le' => '+Inf'], $h['count'] ?? 0);
        $this->sample('hookrelay_attempt_duration_seconds_sum', [], $h['sum'] ?? 0);
        $this->sample('hookrelay_attempt_duration_seconds_count', [], $h['count'] ?? 0);

        $this->family('hookrelay_queue_depth', 'gauge', 'Jobs waiting in the Redis queue (ready + delayed + reserved).');
        foreach ([config('relay.dispatcher.queue'), 'default'] as $queue) {
            $this->sample('hookrelay_queue_depth', ['queue' => $queue], Queue::connection('redis')->size($queue));
        }

        $this->family('hookrelay_circuit_breaker_state', 'gauge', 'Circuit breaker per destination: 0 closed, 1 open, 2 half-open.');
        $states = [CircuitBreaker::CLOSED => 0, CircuitBreaker::OPEN => 1, CircuitBreaker::HALF_OPEN => 2];
        foreach (Destination::query()->orderBy('name')->get(['id', 'name']) as $destination) {
            $state = $breaker->status($destination->id)['state'];
            $this->sample('hookrelay_circuit_breaker_state', ['destination_id' => $destination->id, 'destination' => $destination->name], $states[$state]);
        }

        $this->family('hookrelay_alerts_total', 'counter', 'Alerts by kind and result (sent, suppressed by cooldown).');
        foreach ($redis->hgetall(Metrics::ALERTS_KEY) ?: [] as $field => $n) {
            [$kind, $result] = array_pad(explode(':', $field, 2), 2, '');
            $this->sample('hookrelay_alerts_total', ['kind' => $kind, 'result' => $result], $n);
        }

        return response(implode("\n", $this->lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    private function family(string $name, string $type, string $help): void
    {
        $this->lines[] = "# HELP {$name} {$help}";
        $this->lines[] = "# TYPE {$name} {$type}";
    }

    private function sample(string $name, array $labels, int|float|string $value): void
    {
        $pairs = [];
        foreach ($labels as $k => $v) {
            $pairs[] = $k.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $v).'"';
        }
        $this->lines[] = $name.($pairs ? '{'.implode(',', $pairs).'}' : '').' '.(is_numeric($value) ? $value + 0 : 0);
    }
}
