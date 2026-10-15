<?php

use App\Enums\DeliveryStatus;
use App\Models\Attempt;
use App\Models\Delivery;
use App\Models\WebhookEvent;
use App\Services\Delivery\Backoff;
use App\Services\Delivery\Deliverer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/** Ingest a webhook and return its (single) delivery, already claimed as the dispatcher would. */
function claimedDelivery(array $destinationAttributes = [], string $body = '{"order":42}'): Delivery
{
    [$source] = sourceWithDestinations(destinationAttributes: $destinationAttributes);
    test()->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(202);

    $delivery = Delivery::firstOrFail();
    $delivery->update(['status' => DeliveryStatus::Delivering]);

    return $delivery;
}

function runAttempt(Delivery $delivery): ?int
{
    $delivery->refresh();
    if ($delivery->status === DeliveryStatus::Pending) {
        $delivery->update(['status' => DeliveryStatus::Delivering]); // what the dispatcher does
    }

    return app(Deliverer::class)->deliver($delivery->id);
}

it('posts the original body with HookRelay headers and marks the delivery succeeded on 2xx', function () {
    Http::fake(['*' => Http::response('thanks', 200)]);
    $delivery = claimedDelivery(['auth_header' => 'Bearer dest-token']);
    $event = WebhookEvent::first();

    runAttempt($delivery);

    Http::assertSent(function (Request $request) use ($delivery, $event) {
        return $request->url() === 'https://receiver.test/hook0'
            && $request->body() === '{"order":42}'
            && $request->header('Content-Type')[0] === 'application/json'
            && $request->header('X-HookRelay-Event-Id')[0] === $event->id
            && $request->header('X-HookRelay-Delivery-Id')[0] === $delivery->id
            && $request->header('X-HookRelay-Attempt')[0] === '1'
            && $request->header('X-HookRelay-Signature')[0] === 'sha256='.hash_hmac('sha256', '{"order":42}', 'test-outbound-secret')
            && $request->header('Authorization')[0] === 'Bearer dest-token';
    });

    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryStatus::Succeeded)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->next_attempt_at)->toBeNull();

    $attempt = Attempt::sole();
    expect($attempt->response_status)->toBe(200)
        ->and($attempt->response_body)->toBe('thanks')
        ->and($attempt->error)->toBeNull()
        ->and($attempt->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('schedules a retry within the backoff bounds on a non-2xx response', function () {
    Http::fake(['*' => Http::response('nope', 503)]);
    $delivery = claimedDelivery();
    Carbon::setTestNow($now = Carbon::now()->startOfSecond());

    runAttempt($delivery);

    $delivery->refresh();
    $ceiling = app(Backoff::class)->ceiling(1); // 30 s
    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->last_error)->toBe('HTTP 503')
        ->and($delivery->next_attempt_at->getTimestamp())->toBeGreaterThanOrEqual($now->getTimestamp())
        ->and($delivery->next_attempt_at->getTimestamp())->toBeLessThanOrEqual($now->getTimestamp() + $ceiling)
        ->and(Attempt::sole()->response_status)->toBe(503);
});

it('records timeouts and connection errors as failed attempts', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 1000 milliseconds'));
    $delivery = claimedDelivery(['timeout_ms' => 1000]);

    runAttempt($delivery);

    $attempt = Attempt::sole();
    expect($attempt->response_status)->toBeNull()
        ->and($attempt->error)->toContain('timed out')
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempt_count)->toBe(1);
});

it('respects the destination timeout against a real slow endpoint', function () {
    // Unroutable address (RFC 5737 TEST-NET-1) never answers; the 300 ms timeout must cut it off.
    $delivery = claimedDelivery(['url' => 'http://192.0.2.1:81/hook', 'timeout_ms' => 300]);

    $started = microtime(true);
    runAttempt($delivery);

    expect(microtime(true) - $started)->toBeLessThan(3)
        ->and(Attempt::sole()->error)->not->toBeNull();
});

it('moves the delivery to dead after max_attempts failures', function () {
    Http::fake(['*' => Http::response('down', 500)]);
    $delivery = claimedDelivery(['max_attempts' => 3]);

    foreach (range(1, 3) as $_) {
        runAttempt($delivery);
    }

    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryStatus::Dead)
        ->and($delivery->attempt_count)->toBe(3)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->last_error)->toBe('HTTP 500')
        ->and(Attempt::count())->toBe(3);

    // A stray extra job for a dead delivery does nothing.
    app(Deliverer::class)->deliver($delivery->id);
    expect(Attempt::count())->toBe(3);
    Http::assertSentCount(3);
});

it('increments X-HookRelay-Attempt on each retry', function () {
    Http::fakeSequence()->push('', 500)->push('', 500)->push('', 200);
    $delivery = claimedDelivery();

    foreach (range(1, 3) as $_) {
        runAttempt($delivery);
    }

    $attempts = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('X-HookRelay-Attempt')[0]);
    expect($attempts->all())->toBe(['1', '2', '3'])
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Succeeded);
});

it('truncates stored response bodies to 4 KB', function () {
    Http::fake(['*' => Http::response(str_repeat('x', 10_000), 500)]);
    runAttempt(claimedDelivery());

    expect(strlen(Attempt::sole()->response_body))->toBe(4096);
});

it('ignores jobs for deliveries that are not in the delivering state', function () {
    Http::fake();
    $delivery = claimedDelivery();
    $delivery->update(['status' => DeliveryStatus::Succeeded]);

    expect(app(Deliverer::class)->deliver($delivery->id))->toBeNull();
    Http::assertNothingSent();
});

it('sends custom auth headers given as "Name: value"', function () {
    Http::fake();
    runAttempt(claimedDelivery(['auth_header' => 'X-Api-Key: k-123']));

    Http::assertSent(fn (Request $r) => $r->header('X-Api-Key')[0] === 'k-123' && ! $r->hasHeader('Authorization'));
});
