<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryStatus;
use App\Models\Attempt;
use App\Models\Delivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
    ) {}

    /**
     * @return int|null seconds after which the job should be released back to the queue
     *                  (rate limited, no attempt counted), or null when the job is done.
     */
    public function deliver(string $deliveryId): ?int
    {
        $delivery = Delivery::with(['event', 'destination'])->find($deliveryId);

        // Stale or duplicate job (replayed, recovered, already finished): nothing to do.
        if (! $delivery || $delivery->status !== DeliveryStatus::Delivering) {
            return null;
        }

        $destination = $delivery->destination;

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

        try {
            $response = Http::withHeaders($headers)
                ->withOptions([
                    'timeout' => $destination->timeout_ms / 1000,
                    'connect_timeout' => min(5, $destination->timeout_ms / 1000),
                    'allow_redirects' => false,
                ])
                ->withBody($body, $headers['Content-Type'])
                ->post($destination->url);

            $status = $response->status();
            $responseBody = $this->truncate($response->body());
            if (! $response->successful()) {
                $error = "HTTP {$status}";
            }
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

        if ($error === null) {
            $this->breaker->recordSuccess($destination->id);
            $this->transition($delivery, [
                'status' => DeliveryStatus::Succeeded,
                'attempt_count' => $attemptNumber,
                'next_attempt_at' => null,
                'last_error' => null,
            ]);

            return;
        }

        $this->breaker->recordFailure($destination->id);

        if ($attemptNumber >= $destination->max_attempts) {
            $this->transition($delivery, [
                'status' => DeliveryStatus::Dead,
                'attempt_count' => $attemptNumber,
                'next_attempt_at' => null,
                'last_error' => $error,
            ]);

            return;
        }

        $this->transition($delivery, [
            'status' => DeliveryStatus::Pending,
            'attempt_count' => $attemptNumber,
            'next_attempt_at' => Carbon::now()->addSeconds($this->backoff->delay($attemptNumber)),
            'last_error' => $error,
        ]);
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
