<?php

return [
    // Static bearer token protecting /api/*.
    // TODO(auth): replace with per-user tokens / SSO when auth grows beyond a single admin.
    'admin_token' => env('ADMIN_TOKEN'),

    // relay:prune deletes delivered events older than this (never inside a source's dedupe window).
    'retention_days' => (int) env('RELAY_RETENTION_DAYS', 7),

    // Dashboard session lifetime (minutes) after login.
    'session_minutes' => (int) env('RELAY_SESSION_MINUTES', 720),

    // Public demo mode: a restricted demo user (credentials below, shown on the login page),
    // a "data resets nightly" banner and a scheduled nightly reset. Turn OFF for real installs.
    'demo' => [
        'enabled' => filter_var(env('DEMO_MODE', false), FILTER_VALIDATE_BOOL),
        'email' => 'demo@hookrelay.local',
        'password' => 'hookrelay-demo',
        // Demo users may only create destinations on these hosts.
        'allowed_destination_hosts' => array_filter(array_map('trim', explode(',', (string) env('DEMO_DESTINATION_HOSTS', 'mock-receiver')))),
        'reset_at' => env('DEMO_RESET_AT', '03:00'),
    ],

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

    // SSRF guard: destinations may not resolve to private/loopback/link-local/metadata/ULA
    // addresses unless listed here (hostnames, *.suffix wildcards, IPs or CIDR ranges).
    'ssrf_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('RELAY_SSRF_ALLOWLIST', ''))))),

    'ingest' => [
        // Bodies larger than this get 413. Keep below PHP's post_max_size (10M in the image).
        'max_body_bytes' => (int) env('RELAY_MAX_BODY_BYTES', 2 * 1024 * 1024),
        // Per-source requests/second when the source has no rate_limit_rps of its own. Empty = unlimited.
        'default_rps' => env('RELAY_INGEST_DEFAULT_RPS') !== null && env('RELAY_INGEST_DEFAULT_RPS') !== '' ? (int) env('RELAY_INGEST_DEFAULT_RPS') : null,
    ],

    // Max bytes of a destination response body kept per attempt.
    'response_body_limit' => 4096,
];
