# Security policy

## Reporting a vulnerability

Please **don't open a public issue** for security problems. Use GitHub's [private vulnerability reporting](https://github.com/IreDanielOlaPeters/hookrelay/security/advisories/new) for this repository. Include:

- what you found and where (file, endpoint);
- steps to reproduce, or a proof of concept;
- the impact as you understand it.

You'll get an acknowledgement within 5 working days. Fixes are released as soon as practical, and reporters are credited unless they prefer otherwise.

## Supported versions

HookRelay is pre-1.0. Only the latest release on `main` receives security fixes.

## Running HookRelay safely

The Compose file ships **development defaults** so a fresh clone works with one command. Before exposing an instance:

1. **Set real secrets** in a root `.env`: `APP_KEY` (`docker compose run --rm api php artisan key:generate --show`), `ADMIN_TOKEN`, `RELAY_OUTBOUND_SECRET` and `POSTGRES_PASSWORD`. With `APP_ENV=production`, every API container (`relay:preflight`) refuses to start while `APP_KEY`, `ADMIN_TOKEN` or `RELAY_OUTBOUND_SECRET` is empty or a known default.
2. **Set `DEMO_MODE=false`** unless you're running the public demo. The demo account's credentials (`demo@hookrelay.local` / `hookrelay-demo`) are published on purpose. With demo mode off, the demo user is deleted on boot and demo logins are refused.
3. **Create an admin** with `php artisan relay:admin you@example.com --generate`.
4. **Serve over HTTPS** behind a TLS-terminating proxy that sets `X-Forwarded-Proto`. The dashboard then marks its session cookie `Secure` and sends HSTS.

## What's in place

| Area | Measure |
|---|---|
| Dashboard auth | Email and password login. Server-side sessions: only a SHA-256 of the 64-character token is stored, and it expires after `RELAY_SESSION_MINUTES`. The token lives in an `httpOnly`, `SameSite=Lax` cookie. Every page and server action requires a session, and the API re-validates it on each call. |
| Roles | `admin` has full access. `demo` (only in `DEMO_MODE`) can browse, send test webhooks and replay, and can't delete or change settings. The API enforces this, not just the UI. |
| Brute force | Login is throttled per email (10 a minute) and per IP (30 a minute). Unknown emails and wrong passwords get the same response and similar timing. |
| API tokens | `ADMIN_TOKEN` is compared with `hash_equals`. The dashboard doesn't hold it. |
| Webhook signatures | Stripe, GitHub, Shopify, WooCommerce and generic HMAC, using constant-time comparison and Stripe's 5-minute timestamp tolerance. Outbound requests are signed with `X-HookRelay-Signature`. |
| Secrets at rest | Source signing secrets and destination auth headers are encrypted with `APP_KEY`. They're never returned by the API, and forms never echo them back. |
| Headers | Dashboard: a nonce-based CSP (`script-src 'self' 'nonce-…' 'strict-dynamic'`, `frame-ancestors 'none'`), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy` and `Permissions-Policy`. API: `default-src 'none'` plus the same framing and `nosniff` headers. Both send HSTS only over HTTPS. |
| SSRF | Destinations can't target private, loopback, link-local or cloud-metadata (`169.254.169.254`, `fd00:ec2::254`, `100.100.100.200`), CGNAT, multicast or IPv6 unique-local addresses. IPv4-mapped, NAT64 and 6to4 forms are covered too, as are names like `localhost`, `*.internal` and `metadata`. URLs are checked when saved and again at delivery, where every resolved address must pass and the connection is pinned to the checked IP (cURL `RESOLVE`) against DNS rebinding. Redirects are never followed. Self-hosters can opt internal targets in with `RELAY_SSRF_ALLOWLIST`. |
| Ingestion limits | Per-source token-bucket rate limit (`429` with `Retry-After`), checked before signature verification. A configurable max body size (`413`) is checked on `Content-Length` before the body is read. |
| Redirects | The login `?next=` parameter accepts only same-site relative paths, so it can't be used as an open redirect. |
| Dependencies | CI fails on any `composer audit` advisory and on high or critical `npm audit` findings. Dependabot opens weekly update PRs. |

## Known limitations

These are tracked in the README roadmap:

- There's no password reset, no MFA and no SSO.
- `RELAY_SSRF_ALLOWLIST` entries are trusted completely. A hostname entry skips the IP check and the pinning, so prefer CIDR entries for internal ranges.
