# Changelog

All notable changes to HookRelay. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/) (pre-1.0: minor versions may break).

## [Unreleased]

## [0.2.0] - 2026-10-15

Phase 2 makes HookRelay safe to expose and observable in production.

### Breaking

- **Deliveries that answer a permanent `4xx` (anything but `408`/`429`), or whose target is blocked by the SSRF guard, now end in `failed` without retries.** Before, they retried until `dead`. `5xx`, `408`, `429`, timeouts and DNS failures still retry. See README → *Decision: `failed` means "permanent, don't retry"*.
- **`POST /api/deliveries/replay` returns `202` with a replay batch** (`{data: {id, status, total, processed, replayed, …}}`) instead of `200 {replayed}`. Bulk replays now run as a queued job; poll `GET /api/replay-batches/{id}`.
- **The dashboard needs a login.** It no longer reads `ADMIN_TOKEN`, and every call uses the signed-in user's session. `ADMIN_TOKEN` still works for scripts.
- **API containers refuse to start with `APP_ENV=production`** while `APP_KEY`, `ADMIN_TOKEN` or `RELAY_OUTBOUND_SECRET` is empty or a development default.
- **Upgrades:** Laravel 11 → 12, Next.js 14 → 16 (React 19) and Tailwind 3 → 4.

### Added

- **Dashboard auth:**
  - email and password login with server-side sessions (hashed tokens, httpOnly cookie);
  - `php artisan relay:admin` to create the full-access admin;
  - every page and server action is protected.
- **Demo mode (`DEMO_MODE=true`, the Compose default):**
  - a demo user (`demo@hookrelay.local` / `hookrelay-demo`) is created on boot, idempotently, and its credentials are pre-filled on the login page;
  - the demo user can browse, send test webhooks and replay, but can't delete data or change settings, and can create destinations only on the mock receiver;
  - a "Demo mode, data resets nightly" banner, and a nightly `relay:demo-reset`;
  - with `DEMO_MODE=false`, the demo user is removed on boot.
- **Send test webhooks** from the dashboard: `POST /api/sources/{id}/test`.
- **Security headers:**
  - dashboard: a nonce-based CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy` and `Permissions-Policy`;
  - API: `default-src 'none'`;
  - both send HSTS only over HTTPS.
- **`SECURITY.md`.**
- **SSRF guard:**
  - destinations can't target private, loopback, link-local or metadata, CGNAT, multicast or IPv6 unique-local addresses, including IPv4-mapped, NAT64 and 6to4 forms;
  - URLs are checked on save and again at delivery, after DNS resolution, with the connection pinned to the checked IP;
  - redirects are never followed;
  - `RELAY_SSRF_ALLOWLIST` opts internal targets in.
- **Ingestion limits:** per-source rate limits (`rate_limit_rps` or `RELAY_INGEST_DEFAULT_RPS`), which return `429` with `Retry-After`, and `RELAY_MAX_BODY_BYTES`, which returns `413`.
- **`relay:prune`:** runs daily; retention is `RELAY_RETENTION_DAYS` (default 7); deletes in batches; never deletes undelivered events or events inside their dedupe window.
- **Bulk replay as a background job:** `replay_batches` table, `GET /api/replay-batches`, and a live progress bar in the dashboard. The dead-letter view has *Dead* and *Failed* tabs.
- **Operations:**
  - `/health`, `/ready`, `relay:health` with Redis heartbeats, and Compose healthchecks for every service;
  - Prometheus `/metrics` (events, deliveries by status, attempt latency histogram, queue depth, breaker state, alerts);
  - JSON logs carrying `event_id`/`delivery_id`;
  - rate-limited Slack and email alerts when a delivery goes dead or a circuit breaker opens;
  - a new `cron` service for scheduled tasks.
- **Load tests:** k6 scenarios in `loadtest/` (steady, 10× burst, slow destination, peak ingest and drain), a benchmark harness, and [BENCHMARKS.md](BENCHMARKS.md). A CI k6 smoke run fails if ingestion p95 reaches 100 ms.
- **Docs:** README screenshots plus *Design decisions* and *Lessons learned* sections.
- **CI and repo:**
  - `composer audit` and `npm audit` (high or critical) in CI, plus Dependabot for Composer, npm, Docker and Actions;
  - the dashboard has a Vitest suite;
  - `CONTRIBUTING.md`, issue and PR templates.

### Changed

- **Performance:** the API reuses one Postgres connection per FrankenPHP thread (`DB_PERSISTENT`), and containers cache config, routes and events at boot. Peak ingestion went from ~165 to ~1,500 webhooks/s (see BENCHMARKS.md).
- **Lint config:** ESLint 9 with typescript-eslint, react, react-hooks and jsx-a11y, instead of `eslint-config-next`, which pulls in a `braces` version with an advisory that has no fix.
- **Forms:** keep user input after validation errors (React 19 resets forms after actions).
- **Circuit breaker:** a permanent `4xx` closes a half-open circuit, because the destination is up.

### Fixed

- `relay:demo-reset` also clears replay batches.
- Containers no longer fail to boot from a fresh clone: they cache only config, routes and events, because the API has no views.
- The login throttle no longer locks out public demo visitors. The demo account's published password needs no per-email limit, and all dashboard logins share one IP.

## [0.1.0] - 2026-10-15

Phase 1: the core engine.

- Ingestion at `POST /in/{slug}`:
  - signature verification for Stripe, GitHub, Shopify, WooCommerce and generic HMAC;
  - idempotency keys;
  - a transactional outbox.
- A dispatcher using `FOR UPDATE SKIP LOCKED`, Redis-queued delivery workers, exponential backoff with full jitter, a dead-letter state, and single and bulk replay.
- A per-destination Redis token-bucket rate limiter and circuit breaker.
- The management API, a Next.js dashboard, a mock receiver, Docker Compose, the Makefile, CI and the MIT licence.

[Unreleased]: https://github.com/IreDanielOlaPeters/hookrelay/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/IreDanielOlaPeters/hookrelay/compare/1e4ea60...v0.2.0
[0.1.0]: https://github.com/IreDanielOlaPeters/hookrelay/tree/1e4ea60
