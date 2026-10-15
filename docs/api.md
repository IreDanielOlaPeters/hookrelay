# HookRelay management API

All `/api/*` routes (except login and the demo hint) require `Authorization: Bearer <token>` and speak JSON. The token is either:

- the static `ADMIN_TOKEN`, which acts as an admin (for scripts and automation); or
- a session token from `POST /api/auth/login`, which acts as that user. This is what the dashboard uses.

There are two roles. **admin** has full access. **demo** exists only when `DEMO_MODE=true`. It can read everything, send test webhooks, replay, and create sources, connections and destinations, but destinations only on `DEMO_DESTINATION_HOSTS`. Any `PUT`/`PATCH`/`DELETE` from a demo user returns `403`.
Timestamps are ISO-8601 UTC strings. IDs are UUIDs except connections and attempts (integers).
Validation errors return `422 {"message": "...", "errors": {"field": ["..."]}}`.

## Ingestion (public)

`POST /in/{source_slug}` with any body.

| Result | Status | Body |
|---|---|---|
| Accepted | `202` | `{"event_id": "uuid", "deliveries": 2}` |
| Duplicate (same idempotency key) | `200` | `{"event_id": "uuid", "duplicate": true}` |
| Bad / missing signature | `401` | `{"message": "Invalid signature."}` |
| Unknown source | `404` | `{"message": "Unknown source."}` |

## Auth

| Method | Path | Notes |
|---|---|---|
| POST | `/api/auth/login` | `{email, password}` → `{token, expires_at, user:{id,name,email,role}}`. `422` for bad credentials (the same message whether the email or the password is wrong). `429` after 10 attempts a minute per email (the public demo account is exempt) or 120 a minute per client IP. Public. |
| GET | `/api/auth/demo` | `{demo_mode:false}`, or `{demo_mode:true, email, password}` with the public demo credentials. Public. |
| GET | `/api/auth/me` | `{user, demo_mode}` |
| POST | `/api/auth/logout` | Ends the session → `204` |

## Resources

**Source**
```json
{"id":"uuid","name":"Demo","slug":"demo","provider":"generic|stripe|github|shopify|woocommerce",
 "has_signing_secret":false,"dedupe_window_seconds":86400,"ingest_url":"http://localhost:8080/in/demo",
 "created_at":"...","updated_at":"..."}
```

**Destination**
```json
{"id":"uuid","name":"OK receiver","url":"http://mock-receiver:4000/ok","has_auth_header":false,
 "timeout_ms":10000,"rate_limit_rps":null,"max_attempts":6,"enabled":true,
 "circuit":{"state":"closed|open|half_open","consecutive_failures":0,"open_until":null},
 "created_at":"...","updated_at":"..."}
```

**Connection**
```json
{"id":1,"source_id":"uuid","destination_id":"uuid","enabled":true,
 "source":{"id":"uuid","name":"Demo","slug":"demo"},"destination":{"id":"uuid","name":"OK receiver"},
 "created_at":"..."}
```

**Event** (list item)
```json
{"id":"uuid","source_id":"uuid","source":{"id":"uuid","name":"Demo","slug":"demo","provider":"generic"},
 "idempotency_key":"...","content_type":"application/json","verified":false,"received_at":"...",
 "body_size":17,"delivery_counts":{"pending":0,"delivering":0,"succeeded":2,"failed":0,"dead":0}}
```
Event detail (`GET /api/events/{id}`) adds `"headers": {"name": ["value", ...]}`, `"body": "raw string"`,
`"body_encoding": "utf8|base64"` and `"deliveries": [Delivery with attempts]`.

**Delivery**
```json
{"id":"uuid","event_id":"uuid","destination_id":"uuid",
 "destination":{"id":"uuid","name":"Flaky receiver","url":"..."},
 "event":{"id":"uuid","source_id":"uuid","received_at":"..."},
 "status":"pending|delivering|succeeded|failed|dead","attempt_count":3,"max_attempts":6,
 "next_attempt_at":"...|null","last_error":"HTTP 500|null","created_at":"...","updated_at":"...",
 "attempts":[{"id":1,"started_at":"...","duration_ms":12,"response_status":500,"response_body":"...","error":null}]}
```
`attempts` is included on `GET /api/deliveries/{id}`, on replay responses and inside event detail.

**ReplayBatch**
```json
{"id":"uuid","status":"queued|running|completed|failed","filters":{"status":"dead","destination_id":"uuid"},
 "total":1203,"processed":500,"replayed":498,"progress":0.4156,"error":null,
 "created_at":"...","started_at":"...","finished_at":null}
```

**Delivery statuses:** `pending`, `delivering`, `succeeded`, `failed` (permanent 4xx other than 408/429, or an SSRF-blocked target; not retried) and `dead` (out of retries).

**Paginated lists** use Laravel's shape:
```json
{"data":[...],"links":{"first":"...","last":"...","prev":null,"next":"..."},
 "meta":{"current_page":1,"last_page":3,"per_page":25,"total":61,"from":1,"to":25}}
```

## Endpoints

| Method | Path | Notes |
|---|---|---|
| GET | `/api/sources` | all sources (not paginated) |
| POST | `/api/sources` | `{name, slug, provider?, signing_secret?, dedupe_window_seconds?}` → 201 `{data: Source}` |
| GET/PUT/DELETE | `/api/sources/{id}` | PUT accepts the same fields (all optional); `signing_secret: null` clears it. PUT/DELETE: admin only |
| POST | `/api/sources/{id}/test` | `{body?}` → `202 {event_id, deliveries}`. Sends a test webhook through the normal pipeline: no signature check, never deduped, and the event carries an `X-HookRelay-Test` header |
| GET | `/api/destinations` | all destinations |
| POST | `/api/destinations` | `{name, url, auth_header?, timeout_ms?, rate_limit_rps?, max_attempts?, enabled?}` → 201 |
| GET/PUT/DELETE | `/api/destinations/{id}` | PUT/DELETE: admin only |
| GET | `/api/connections` | all connections, filter `?source_id=` |
| POST | `/api/connections` | `{source_id, destination_id, enabled?}` → 201 |
| PUT/DELETE | `/api/connections/{id}` | PUT `{enabled}`. Admin only |
| GET | `/api/events` | paginated; `?source_id=&from=&to=&page=&per_page=` (from/to are dates or datetimes) |
| GET | `/api/events/{id}` | detail with deliveries + attempts |
| GET | `/api/deliveries` | paginated; `?status=&destination_id=&event_id=&has_error=1&page=&per_page=` newest first (by updated_at) |
| GET | `/api/deliveries/{id}` | with attempts |
| POST | `/api/deliveries/{id}/replay` | → `{data: Delivery}`; 409 if currently `delivering` |
| POST | `/api/deliveries/replay` | `{status?: "dead" (default) \| "failed" \| "succeeded", destination_id?, from?, to?}` → `202 {data: ReplayBatch}`. Runs as a queued job |
| GET | `/api/replay-batches` | The 20 most recent batches |
| GET | `/api/replay-batches/{id}` | `{data: ReplayBatch}`, for polling progress |
| GET | `/api/stats` | see below |

**Stats** (last 24 h, by delivery `created_at`):
```json
{"window_hours":24,"events_received":120,
 "deliveries":{"pending":1,"delivering":0,"succeeded":200,"failed":0,"dead":3,"total":204},
 "latency_ms":{"attempt":{"p50":12,"p95":80},"end_to_end":{"p50":900,"p95":45000}}}
```
`attempt` = HTTP round-trip per attempt; `end_to_end` = event received → delivery succeeded. Values may be `null`.
