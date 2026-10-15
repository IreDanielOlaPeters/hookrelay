<?php

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Services\Delivery\CircuitBreaker;
use App\Services\Delivery\Deliverer;
use App\Services\Observability\Heartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Monolog\Handler\TestHandler;

function claimed(array $destinationAttributes = []): Delivery
{
    [$source] = sourceWithDestinations(destinationAttributes: $destinationAttributes);
    $eventId = test()->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"o":'.random_int(1, 1e9).'}')->json('event_id');
    $delivery = Delivery::where('event_id', $eventId)->sole();
    $delivery->update(['status' => DeliveryStatus::Delivering]);

    return $delivery;
}

describe('health', function () {
    it('answers liveness without touching dependencies', function () {
        $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok']);
    });

    it('reports readiness of database, schema and redis', function () {
        $this->getJson('/ready')->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.schema.ok', true)
            ->assertJsonPath('checks.redis.ok', true);
    });

    it('returns 503 when a dependency is down', function () {
        $config = config('database.redis');
        $config['default']['port'] = 1; // nothing listens there
        $config['default']['timeout'] = 0.2;
        app()->instance('redis', new RedisManager(app(), 'phpredis', $config));
        Redis::clearResolvedInstances();

        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('checks.redis.ok', false);
    });

    it('checks heartbeats for workers, the dispatcher and cron', function () {
        Heartbeat::reset();
        $this->artisan('relay:health')->assertSuccessful();
        $this->artisan('relay:health', ['--role' => 'worker'])->assertFailed();

        Heartbeat::beat('worker');
        $this->artisan('relay:health', ['--role' => 'worker'])->assertSuccessful();

        $this->travel(61)->seconds();
        $this->artisan('relay:health', ['--role' => 'worker'])->expectsOutputToContain('No fresh worker heartbeat')->assertFailed();

        $this->artisan('relay:heartbeat', ['role' => 'cron'])->assertSuccessful();
        $this->artisan('relay:health', ['--role' => 'cron'])->assertSuccessful();
    });

    it('schedules the cron heartbeat every minute', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'relay:heartbeat cron'));
        expect($events->first()?->expression)->toBe('* * * * *');
    });
});

describe('metrics', function () {
    it('exposes Prometheus metrics for events, deliveries, attempts, queue depth and breakers', function () {
        Http::fake(['*' => Http::sequence()->push('ok', 200)->push('no', 500)]);
        $ok = claimed(['name' => 'Alpha']);
        app(Deliverer::class)->deliver($ok->id);
        $retry = claimed(['name' => 'Beta']);
        app(Deliverer::class)->deliver($retry->id);
        foreach (range(1, 5) as $_) {
            app(CircuitBreaker::class)->recordFailure($retry->destination_id);
        }

        $body = $this->get('/metrics')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->getContent();

        expect($body)
            ->toContain('# TYPE hookrelay_events_received_total counter')
            ->toMatch('/^hookrelay_events_received_total\{source="src-[a-z]+"\} 1$/m')
            ->toContain('hookrelay_deliveries{status="succeeded"} 1')
            ->toContain('hookrelay_deliveries{status="pending"} 1')
            ->toContain('hookrelay_delivery_attempts_total{outcome="success"} 1')
            ->toContain('hookrelay_delivery_attempts_total{outcome="retry"} 1')
            ->toContain('# TYPE hookrelay_attempt_duration_seconds histogram')
            ->toContain('hookrelay_attempt_duration_seconds_bucket{le="+Inf"} 2')
            ->toContain('hookrelay_attempt_duration_seconds_count 2')
            ->toContain('hookrelay_queue_depth{queue="deliveries"}')
            ->toMatch('/hookrelay_circuit_breaker_state\{destination_id="[0-9a-f-]+",destination="Beta"\} 1/')
            ->toMatch('/hookrelay_circuit_breaker_state\{destination_id="[0-9a-f-]+",destination="Alpha"\} 0/');

        // Histogram buckets are cumulative.
        preg_match_all('/hookrelay_attempt_duration_seconds_bucket\{le="([^"]+)"\} (\d+)/', $body, $m);
        $counts = array_map('intval', $m[2]);
        for ($i = 1; $i < count($counts); $i++) {
            expect($counts[$i])->toBeGreaterThanOrEqual($counts[$i - 1]);
        }
    });

    it('does not count duplicates as received events', function () {
        [$source] = sourceWithDestinations();
        $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"d":1}');
        $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"d":1}');

        expect($this->get('/metrics')->getContent())->toContain("hookrelay_events_received_total{source=\"{$source->slug}\"} 1");
    });

    it('requires the metrics token when one is configured', function () {
        config(['relay.metrics_token' => 'scrape-me']);

        $this->get('/metrics')->assertUnauthorized();
        $this->get('/metrics', ['Authorization' => 'Bearer scrape-me'])->assertOk();
    });
});

describe('structured logs', function () {
    beforeEach(function () {
        $this->handler = new TestHandler;
        Log::getLogger()->pushHandler($this->handler);
    });

    it('tags ingestion and delivery log lines with event_id and delivery_id', function () {
        Http::fake(['*' => Http::response('', 503)]);
        $delivery = claimed();
        app(Deliverer::class)->deliver($delivery->id);

        $records = collect($this->handler->getRecords());
        $received = $records->firstWhere('message', 'webhook.received');
        $retry = $records->firstWhere('message', 'delivery.retry_scheduled');

        expect($received->extra['event_id'] ?? null)->toBe($delivery->event_id)
            ->and($retry->extra['delivery_id'] ?? null)->toBe($delivery->id)
            ->and($retry->extra['event_id'] ?? null)->toBe($delivery->event_id)
            ->and($retry->context['status'])->toBe(503)
            ->and($retry->context['attempt'])->toBe(1);
    });

    it('clears the delivery context after each job', function () {
        Http::fake();
        $delivery = claimed();
        app(Deliverer::class)->deliver($delivery->id);

        expect(Context::has('delivery_id'))->toBeFalse();
    });
});
