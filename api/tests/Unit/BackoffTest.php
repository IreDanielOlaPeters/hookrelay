<?php

use App\Services\Delivery\Backoff;

it('grows the ceiling by the factor and caps it at 6 hours', function () {
    $backoff = new Backoff(baseSeconds: 30, factor: 4, capSeconds: 21600);

    expect(array_map(fn ($n) => $backoff->ceiling($n), range(1, 8)))
        ->toBe([30, 120, 480, 1920, 7680, 21600, 21600, 21600]);
});

it('keeps every jittered delay within [0, ceiling]', function () {
    $backoff = new Backoff(30, 4, 21600);

    foreach (range(1, 10) as $attempt) {
        $ceiling = $backoff->ceiling($attempt);
        foreach (range(1, 300) as $_) {
            expect($backoff->delay($attempt))->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($ceiling);
        }
    }
});

it('actually jitters (full jitter, not a fixed delay)', function () {
    $backoff = new Backoff(30, 4, 21600);
    $samples = array_map(fn () => $backoff->delay(4), range(1, 200));

    expect(count(array_unique($samples)))->toBeGreaterThan(50);
});

it('never exceeds the cap even for absurd attempt numbers', function () {
    $backoff = new Backoff(30, 4, 21600);

    expect($backoff->ceiling(1000))->toBe(21600)
        ->and($backoff->delay(1000))->toBeLessThanOrEqual(21600)
        ->and($backoff->ceiling(0))->toBe(30);
});
