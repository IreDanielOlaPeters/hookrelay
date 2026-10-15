<?php

namespace App\Services\Delivery;

/**
 * Exponential backoff with full jitter (AWS Architecture Blog, "Exponential Backoff And Jitter"):
 *
 *   ceiling(n) = min(cap, base * factor^(n - 1))
 *   delay(n)   = random_between(0, ceiling(n))
 *
 * where n is the number of failed attempts so far (1 after the first failure).
 * Defaults: base 30 s, factor 4, cap 6 h -> ceilings 30 s, 2 m, 8 m, 32 m, ~2.1 h, 6 h, 6 h...
 */
class Backoff
{
    public function __construct(
        private readonly int $baseSeconds = 30,
        private readonly int $factor = 4,
        private readonly int $capSeconds = 21600,
    ) {}

    public function ceiling(int $failedAttempts): int
    {
        $n = max(1, $failedAttempts);
        // Compute in floats so large attempt numbers cannot overflow before the cap applies.
        $raw = $this->baseSeconds * ($this->factor ** ($n - 1));

        return (int) min($this->capSeconds, $raw);
    }

    /** Seconds to wait before the next attempt. */
    public function delay(int $failedAttempts): int
    {
        return random_int(0, $this->ceiling($failedAttempts));
    }
}
