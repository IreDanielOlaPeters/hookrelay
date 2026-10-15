<?php

use App\Enums\DeliveryStatus;
use App\Models\Attempt;
use App\Models\Delivery;
use App\Services\Delivery\Deliverer;
use App\Services\Delivery\TokenBucket;
use Illuminate\Support\Facades\Http;

it('allows a burst of rps requests, then refills at rps per second', function () {
    $bucket = new TokenBucket;
    $t = 1_000_000;

    $burst = array_map(fn () => $bucket->take('d1', 3, $t), range(1, 4));
    expect($burst)->toBe([true, true, true, false])
        ->and($bucket->take('d1', 3, $t + 100))->toBeFalse()  // 0.3 tokens
        ->and($bucket->take('d1', 3, $t + 334))->toBeTrue()   // ~1 token refilled
        ->and($bucket->take('d1', 3, $t + 340))->toBeFalse()
        ->and($bucket->take('d1', 3, $t + 5000))->toBeTrue(); // capped at capacity, not 15 tokens
    expect([$bucket->take('d1', 3, $t + 5000), $bucket->take('d1', 3, $t + 5000), $bucket->take('d1', 3, $t + 5000)])
        ->toBe([true, true, false]);
});

it('keeps buckets per destination', function () {
    $bucket = new TokenBucket;
    expect($bucket->take('a', 1, 1000))->toBeTrue()
        ->and($bucket->take('a', 1, 1000))->toBeFalse()
        ->and($bucket->take('b', 1, 1000))->toBeTrue();
});

it('releases the job without an attempt when the destination is out of tokens', function () {
    Http::fake();
    [$source] = sourceWithDestinations(destinationAttributes: ['rate_limit_rps' => 1]);
    $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":1}');
    $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":2}');
    Delivery::query()->update(['status' => 'delivering']);
    [$first, $second] = Delivery::orderBy('id')->get();
    $deliverer = app(Deliverer::class);

    expect($deliverer->deliver($first->id))->toBeNull()
        ->and($deliverer->deliver($second->id))->toBe(1); // release for 1 s

    $second->refresh();
    expect($second->status)->toBe(DeliveryStatus::Delivering) // still owned by the queued job
        ->and($second->attempt_count)->toBe(0)
        ->and(Attempt::where('delivery_id', $second->id)->count())->toBe(0);
    Http::assertSentCount(1);
});
