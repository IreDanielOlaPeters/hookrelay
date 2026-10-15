<?php

return [
    // Static bearer token protecting /api/*.
    // TODO(auth): replace with per-user tokens / SSO when auth grows beyond a single admin.
    'admin_token' => env('ADMIN_TOKEN'),

    // Secret used to sign outbound deliveries (X-HookRelay-Signature).
    'outbound_secret' => env('RELAY_OUTBOUND_SECRET'),

    // Retry schedule: full jitter over min(cap, base * factor^(attempt - 1)).
    'backoff' => [
        'base_seconds' => (int) env('RELAY_BACKOFF_BASE_SECONDS', 30),
        'factor' => (int) env('RELAY_BACKOFF_FACTOR', 4),
        'cap_seconds' => (int) env('RELAY_BACKOFF_CAP_SECONDS', 6 * 3600),
    ],

    'dispatcher' => [
        'batch_size' => (int) env('RELAY_DISPATCH_BATCH', 100),
        'interval_seconds' => (float) env('RELAY_DISPATCH_INTERVAL', 2),
        // Deliveries stuck in `delivering` longer than this are put back to `pending`.
        'stuck_after_seconds' => (int) env('RELAY_STUCK_AFTER_SECONDS', 300),
        'queue' => env('RELAY_QUEUE', 'deliveries'),
    ],

    'circuit_breaker' => [
        'failure_threshold' => (int) env('RELAY_CIRCUIT_THRESHOLD', 5),
        'open_seconds' => (int) env('RELAY_CIRCUIT_OPEN_SECONDS', 60),
    ],

    // Delay before a rate-limited job is retried (does not count as an attempt).
    'rate_limit_release_seconds' => (int) env('RELAY_RATE_LIMIT_RELEASE_SECONDS', 1),

    'stripe_tolerance_seconds' => 300,

    // Max bytes of a destination response body kept per attempt.
    'response_body_limit' => 4096,
];
