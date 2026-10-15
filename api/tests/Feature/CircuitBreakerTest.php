<?php

use App\Enums\DeliveryStatus;
use App\Models\Attempt;
use App\Models\Delivery;
use App\Services\Delivery\CircuitBreaker;
use App\Services\Delivery\Deliverer;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => $this->breaker = new CircuitBreaker(failureThreshold: 5, openSeconds: 60));

it('stays closed below the failure threshold', function () {
    foreach (range(1, 4) as $_) {
        $this->breaker->recordFailure('d1', 1000);
    }

    expect($this->breaker->acquire('d1', 1000))->toBeNull()
        ->and($this->breaker->status('d1', 1000)['state'])->toBe(CircuitBreaker::CLOSED);
});

it('opens after 5 consecutive failures and rejects calls for 60 seconds', function () {
    foreach (range(1, 5) as $_) {
        $this->breaker->recordFailure('d1', 1000);
    }

    expect($this->breaker->status('d1', 1000)['state'])->toBe(CircuitBreaker::OPEN)
        ->and($this->breaker->acquire('d1', 1000))->toBe(1060)
        ->and($this->breaker->acquire('d1', 1059))->toBe(1060);
});

it('resets the consecutive count on success', function () {
    foreach (range(1, 4) as $_) {
        $this->breaker->recordFailure('d1', 1000);
    }
    $this->breaker->recordSuccess('d1');
    foreach (range(1, 4) as $_) {
        $this->breaker->recordFailure('d1', 1000);
    }

    expect($this->breaker->status('d1', 1000)['state'])->toBe(CircuitBreaker::CLOSED);
});

it('allows exactly one probe when half-open and closes on success', function () {
    foreach (range(1, 5) as $_) {
        $this->breaker->recordFailure('d1', 1000);
    }

    expect($this->breaker->status('d1', 1060)['state'])->toBe(CircuitBreaker::HALF_OPEN)
        ->and($this->breaker->acquire('d1', 1060))->toBeNull()      // the probe
        ->and($this->breaker->acquire('d1', 1061))->not->toBeNull(); // everyone else waits

    $this->breaker->recordSuccess('d1');

    expect($this->breaker->status('d1', 1061)['state'])->toBe(CircuitBreaker::CLOSED)
        ->and($this->breaker->acquire('d1', 1061))->toBeNull()
        ->and($this->breaker->status('d1', 1061)['consecutive_failures'])->toBe(0);
});

it('re-opens for another period when the probe fails', function () {
    foreach (range(1, 5) as $_) {
        $this->breaker->recordFailure('d1', 1000);
    }
    expect($this->breaker->acquire('d1', 1060))->toBeNull();

    $this->breaker->recordFailure('d1', 1060);

    expect($this->breaker->status('d1', 1061)['state'])->toBe(CircuitBreaker::OPEN)
        ->and($this->breaker->acquire('d1', 1061))->toBe(1120)
        ->and($this->breaker->acquire('d1', 1120))->toBeNull(); // next probe after the new period
});

it('keeps circuits per destination', function () {
    foreach (range(1, 5) as $_) {
        $this->breaker->recordFailure('d1', 1000);
    }

    expect($this->breaker->acquire('d2', 1000))->toBeNull();
});

it('reschedules deliveries without counting an attempt while the circuit is open', function () {
    Http::fake(['*' => Http::response('', 500)]);
    [$source, [$destination]] = sourceWithDestinations(destinationAttributes: ['max_attempts' => 20]);
    foreach (range(1, 7) as $i) {
        $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], "{\"n\":{$i}}");
    }
    Delivery::query()->update(['status' => 'delivering']);
    $deliverer = app(Deliverer::class);

    // 5 real failures open the circuit; the next two are skipped.
    Delivery::orderBy('id')->get()->each(fn ($d) => $deliverer->deliver($d->id));

    Http::assertSentCount(5);
    expect(Attempt::count())->toBe(5)
        ->and(app(CircuitBreaker::class)->status($destination->id)['state'])->toBe(CircuitBreaker::OPEN);

    $skipped = Delivery::where('attempt_count', 0)->get();
    expect($skipped)->toHaveCount(2)
        ->and($skipped->every(fn ($d) => $d->status === DeliveryStatus::Pending
            && $d->next_attempt_at->isAfter(now()->addSeconds(55))))->toBeTrue();
});

it('closes the circuit after a successful probe through the deliverer', function () {
    [$source, [$destination]] = sourceWithDestinations();
    $breaker = app(CircuitBreaker::class);
    foreach (range(1, 5) as $_) {
        $breaker->recordFailure($destination->id, time() - 120); // opened long ago -> half-open now
    }
    Http::fake(['*' => Http::response('ok', 200)]);
    $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"probe":true}');
    $delivery = Delivery::sole();
    $delivery->update(['status' => 'delivering']);

    app(Deliverer::class)->deliver($delivery->id);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Succeeded)
        ->and($breaker->status($destination->id)['state'])->toBe(CircuitBreaker::CLOSED);
});
