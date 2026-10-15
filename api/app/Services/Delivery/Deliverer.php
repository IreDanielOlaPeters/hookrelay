<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryStatus;
use App\Models\Attempt;
use App\Models\Delivery;
use App\Services\Alerts\Alerter;
use App\Services\Observability\Metrics;
use App\Services\Security\BlockedDestination;
use App\Services\Security\UrlGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Performs one delivery attempt. Called from the DeliverWebhook job.
 */
class Deliverer
{
    public function __construct(
        private readonly Backoff $backoff,
        private readonly CircuitBreaker $breaker,
        private readonly TokenBucket $bucket,
        private readonly UrlGuard $guard,
        private readonly Metrics $metrics,
        private readonly Alerter $alerter,
    ) {}

    /**
     * @return int|null seconds after which the job should be released back to the queue
     *                  (rate limited, no attempt counted), or null when the job is done.
     */
    public function deliver(string $deliveryId): ?int
    {
        // Every log line written while handling this delivery carries these ids.
        Context::add('delivery_id', $deliveryId);
        try {
            return $this->handle($deliveryId);
        } finally {
            Context::forget(['delivery_id', 'event_id', 'destination_id']);
        }
    }

    private function handle(string $deliveryId): ?int
    {
        $delivery = Delivery::with(['event', 'destination'])->find($deliveryId);

        // Stale or duplicate job (replayed, recovered, already finished): nothing to do.
        if (! $delivery || $delivery->status !== DeliveryStatus::Delivering) {
            return null;
        }

        $destination = $delivery->destination;
        Context::add(['event_id' => $delivery->event_id, 'destination_id' => $destination->id]);

        if ($destination->rate_limit_rps && ! $this->bucket->take($destination->id, $destination->rate_limit_rps)) {
            // Touch so the stuck-delivery recovery does not reclaim it while it waits.
            $delivery->touch();

            return (int) config('relay.rate_limit_release_seconds', 1);
        }

        if (($retryAt = $this->breaker->acquire($destination->id)) !== null) {
            // Circuit open: reschedule without spending an attempt. Small jitter avoids a thundering herd.
            $this->transition($delivery, [
                'status' => DeliveryStatus::Pending,
                'next_attempt_at' => Carbon::createFromTimestamp($retryAt)->addSeconds(random_int(0, 5)),
            ]);

            return null;
        }

        $this->attempt($delivery);

        return null;
    }

    private function attempt(Delivery $delivery): void
    {
        $event = $delivery->event;
        $destination = $delivery->destination;
        $attemptNumber = $delivery->attempt_count + 1;
        $body = $event->rawBody();

        $headers = [
            'Content-Type' => $event->content_type ?: 'application/octet-stream',
            'User-Agent' => 'HookRelay/0.1',
            'X-HookRelay-Event-Id' => $event->id,
            'X-HookRelay-Delivery-Id' => $delivery->id,
            'X-HookRelay-Attempt' => (string) $attemptNumber,
            'X-HookRelay-Signature' => 'sha256='.hash_hmac('sha256', $body, (string) config('relay.outbound_secret')),
        ];
        if ($destination->auth_header) {
            [$name, $value] = $this->authHeader($destination->auth_header);
            $headers[$name] = $value;
        }

        $startedAt = Carbon::now();
        $start = hrtime(true);
        $status = null;
        $responseBody = null;
        $error = null;
        $permanent = false;

        try {
            // SSRF guard at delivery time, then pin the connection to the IP we just checked so a
            // second DNS answer (rebinding) can't send the request somewhere else.
            $pinnedIp = $this->guard->resolveForDelivery($destination->url);

            $response = Http::withHeaders($headers)
                ->withOptions([
                    'timeout' => $destination->timeout_ms / 1000,
                    'connect_timeout' => min(5, $destination->timeout_ms / 1000),
                    // Never follow redirects: a 3xx counts as a failure, so a redirect can't
                    // lead to a blocked host either.
                    'allow_redirects' => false,
                    'curl' => $pinnedIp ? [CURLOPT_RESOLVE => [UrlGuard::curlResolveEntry($destination->url, $pinnedIp)]] : [],
                ])
                ->withBody($body, $headers['Content-Type'])
                ->post($destination->url);

            $status = $response->status();
            $responseBody = $this->truncate($response->body());
            if (! $response->successful()) {
                $error = "HTTP {$status}";
                $permanent = self::isPermanentStatus($status);
            }
        } catch (BlockedDestination $e) {
            $error = 'Blocked by SSRF guard: '.$e->getMessage();
            $permanent = $e->permanent;
        } catch (Throwable $e) {
            $error = $this->truncate(class_basename($e).': '.$e->getMessage(), 1000);
        }

        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);

        Attempt::create([
            'delivery_id' => $delivery->id,
            'started_at' => $startedAt,
            'duration_ms' => $durationMs,
            'response_status' => $status,
            'response_body' => $responseBody,
            'error' => $error,
        ]);

        $seconds = $durationMs / 1000;
        $logContext = ['attempt' => $attemptNumber, 'status' => $status, 'duration_ms' => $durationMs, 'error' => $error];

        if ($error === null) {
            $this->metrics->attempt('success', $seconds);
            Log::info('delivery.succeeded', $logContext);
            $this->breaker->recordSuccess($destination->id);
            $this->transition($delivery, [
                'status' => DeliveryStatus::Succeeded,
                'attempt_count' => $attemptNumber,
                'next_attempt_at' => null,
                'last_error' => null,
            ]);

            return;
        }

        // A permanent client error (4xx other than 408/429) or a blocked target won't succeed
        // on retry: mark the delivery `failed` straight away. A 4xx also proves the destination
        // is up, so it closes the circuit rather than counting towards opening it.
        if ($permanent) {
            $this->metrics->attempt('failed', $seconds);
            Log::warning('delivery.failed', $logContext + ['permanent' => true]);
            if ($status !== null) {
                $this->breaker->recordSuccess($destination->id);
            }
            $this->transition($delivery, [
                'status' => DeliveryStatus::Failed,
                'attempt_count' => $attemptNumber,
                'next_attempt_at' => null,
                'last_error' => $error,
            ]);

            return;
        }

        if ($this->breaker->recordFailure($destination->id)) {
            Log::warning('circuit.opened', ['destination' => $destination->name]);
            $this->alerter->notify('circuit_open', $destination->id, "Circuit breaker opened for {$destination->name}", [
                'Destination' => "{$destination->name} ({$destination->url})",
                'Last error' => $error,
                'Paused for' => config('relay.circuit_breaker.open_seconds').' s',
            ]);
        }

        if ($attemptNumber >= $destination->max_attempts) {
            $this->metrics->attempt('dead', $seconds);
            Log::error('delivery.dead', $logContext);
            $this->alerter->notify('delivery_dead', $destination->id, "Delivery to {$destination->name} is dead", [
                'Destination' => "{$destination->name} ({$destination->url})",
                'Event' => $event->id,
                'Delivery' => $delivery->id,
                'Attempts' => $attemptNumber,
                'Last error' => $error,
            ]);
            $this->transition($delivery, [
                'status' => DeliveryStatus::Dead,
                'attempt_count' => $attemptNumber,
                'next_attempt_at' => null,
                'last_error' => $error,
            ]);

            return;
        }

        $delay = $this->backoff->delay($attemptNumber);
        $this->metrics->attempt('retry', $seconds);
        Log::notice('delivery.retry_scheduled', $logContext + ['retry_in_s' => $delay]);
        $this->transition($delivery, [
            'status' => DeliveryStatus::Pending,
            'attempt_count' => $attemptNumber,
            'next_attempt_at' => Carbon::now()->addSeconds($delay),
            'last_error' => $error,
        ]);
    }

    /** 4xx means "this request will never work" except 408 Request Timeout and 429 Too Many Requests. */
    public static function isPermanentStatus(int $status): bool
    {
        return $status >= 400 && $status < 500 && ! in_array($status, [408, 429], true);
    }

    /** Update only if we still own the delivery (status = delivering). */
    private function transition(Delivery $delivery, array $changes): void
    {
        $changes['updated_at'] = Carbon::now();
        if (isset($changes['status'])) {
            $changes['status'] = $changes['status']->value;
        }

        Delivery::query()
            ->whereKey($delivery->id)
            ->where('status', DeliveryStatus::Delivering->value)
            ->update($changes);
    }

    /**
     * "Bearer abc" -> Authorization: Bearer abc
     * "X-Api-Key: abc" -> X-Api-Key: abc
     *
     * @return array{0: string, 1: string}
     */
    private function authHeader(string $value): array
    {
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.+)$/s', $value, $m)) {
            return [$m[1], $m[2]];
        }

        return ['Authorization', $value];
    }

    /** Truncate to a byte limit and make it safe for a Postgres text column. */
    private function truncate(string $value, ?int $limit = null): string
    {
        $value = mb_scrub(mb_strcut($value, 0, $limit ?? config('relay.response_body_limit', 4096), 'UTF-8'), 'UTF-8');

        return str_replace("\0", '', $value);
    }
}
