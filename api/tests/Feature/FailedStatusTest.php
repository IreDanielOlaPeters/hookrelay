<?php

use App\Enums\DeliveryStatus;
use App\Models\Attempt;
use App\Models\Delivery;
use App\Services\Delivery\CircuitBreaker;
use App\Services\Delivery\Deliverer;
use Illuminate\Support\Facades\Http;

function oneDelivery(array $destinationAttributes = []): Delivery
{
    [$source] = sourceWithDestinations(destinationAttributes: $destinationAttributes);
    test()->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"f":1}');
    $delivery = Delivery::sole();
    $delivery->update(['status' => DeliveryStatus::Delivering]);

    return $delivery;
}

it('marks permanent 4xx responses failed immediately, without retries', function (int $status) {
    Http::fake(['*' => Http::response('nope', $status)]);
    $delivery = oneDelivery();

    app(Deliverer::class)->deliver($delivery->id);

    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->last_error)->toBe("HTTP {$status}");
})->with([400, 401, 403, 404, 405, 410, 413, 422]);

it('keeps retrying 408, 429 and 5xx', function (int $status) {
    Http::fake(['*' => Http::response('', $status)]);
    $delivery = oneDelivery();

    app(Deliverer::class)->deliver($delivery->id);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->next_attempt_at)->not->toBeNull();
})->with([408, 429, 500, 502, 503]);

it('treats a 4xx as proof the destination is up: it closes a half-open circuit', function () {
    Http::fake(['*' => Http::response('', 404)]);
    $delivery = oneDelivery();
    $breaker = app(CircuitBreaker::class);
    foreach (range(1, 5) as $_) {
        $breaker->recordFailure($delivery->destination_id, time() - 120);
    }

    app(Deliverer::class)->deliver($delivery->id);

    expect($breaker->status($delivery->destination_id)['state'])->toBe(CircuitBreaker::CLOSED)
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Failed);
});

it('marks SSRF-blocked targets failed, but retries hosts that do not resolve', function () {
    Http::fake();
    $blocked = oneDelivery(['url' => 'https://evil.example/hook']);
    fakeDns(['evil.example' => ['10.0.0.1'], 'gone.example' => []], default: '93.184.216.34');
    app(Deliverer::class)->deliver($blocked->id);
    expect($blocked->refresh()->status)->toBe(DeliveryStatus::Failed);

    Delivery::query()->delete();
    $unresolvable = oneDelivery(['url' => 'https://gone.example/hook']);
    fakeDns(['gone.example' => []], default: null);
    app(Deliverer::class)->deliver($unresolvable->id);
    expect($unresolvable->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and(Attempt::latest('id')->first()->error)->toContain('Could not resolve');
    Http::assertNothingSent();
});

it('lets failed deliveries be replayed, one at a time or in bulk', function () {
    Http::fake(['*' => Http::response('', 404)]);
    $delivery = oneDelivery();
    app(Deliverer::class)->deliver($delivery->id);
    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Failed);

    $this->postJson('/api/deliveries/replay', ['status' => 'failed'], adminHeaders())
        ->assertStatus(202)->assertJsonPath('data.replayed', 1);
    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Pending);
});
