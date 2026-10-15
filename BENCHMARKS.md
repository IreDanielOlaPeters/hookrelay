# Benchmarks

**Headline:** on one laptop, HookRelay ingests **about 1,500 webhooks/s at peak** (p95 ≤ 76 ms). It sustains **300/s at p95 20–26 ms** while delivering at the same time. Delivery capacity is **93 → 154 → 238 deliveries/s with 1 → 2 → 4 workers**. Across every run, 0 requests failed.

These are single-machine numbers from Docker Desktop, meant to show how the design behaves and where it saturates. They aren't a production capacity plan. Run them yourself with `./loadtest/run.sh`, which takes about 45 minutes.

## Setup

| | |
|---|---|
| Machine | Laptop: Intel Core i9-14900HX (24 cores / 32 threads), 32 GB RAM, Windows 11 |
| Docker | Docker Desktop 29.6 on WSL2 (kernel 6.6.87); the VM has 32 vCPUs and 15.5 GB |
| Stack | `docker compose` as shipped: FrankenPHP (64 PHP threads, persistent DB connections), Postgres 16, Redis 7, 1/2/4 `worker` containers, one dispatcher. No resource limits. |
| Load generator | k6 2.3.0 in a container on the same Docker network, sending ~600-byte JSON webhooks, each unique (no dedupe hits) |
| Destination | `mock-receiver` (single-threaded Node): `/ok` answers at once, the "slow" one after 2 s |
| Noise | Other unrelated containers (a SonarQube stack) were idle on the same machine. Laptop thermals vary, so expect a ±10–15% spread between runs. |
| Date / commit | 2026-10-15, Phase 2 (`v0.2.0`) |

Raw k6 summaries and the derived table are in [`loadtest/results/`](loadtest/results/).

## Scenarios

| Scenario | Script | Load |
|---|---|---|
| **Steady** | `steady.js` | 300 webhooks/s for 2 min (constant arrival rate) |
| **Burst** | `burst.js` | 100/s for 60 s → **10× (1,000/s) for 60 s** → 100/s for 60 s |
| **Slow destination** | `slow.js` | 2/s for 60 s into a source whose only destination takes **2 s** to answer |
| **Peak ingest + drain** | `max.js` | Dispatcher paused; 10,000 webhooks from 64 concurrent senders as fast as possible; then the dispatcher restarts and the backlog drains |
| CI smoke | `smoke.js` | 25/s for 30 s. **CI fails if ingestion p95 ≥ 100 ms** |

"Delivery rate" is succeeded deliveries divided by the time from the first event received to the last success. "End-to-end" (e2e) is the time from receipt to success.

## Results

### Ingestion

| Scenario | Workers | Achieved rate | p50 | p95 | p99 | Failed |
|---|---:|---:|---:|---:|---:|---:|
| Steady 300/s | 1 | 301.5/s | 15.7 ms | 22.0 ms | 26.2 ms | 0% |
| Steady 300/s | 2 | 301.3/s | 15.4 ms | 20.4 ms | 23.3 ms | 0% |
| Steady 300/s | 4 | 301.2/s | 18.2 ms | 26.0 ms | 30.2 ms | 0% |
| **Peak (64 senders)** | 1 | **1,600.9/s** | 35.6 ms | 69.0 ms | 87.5 ms | 0% |
| **Peak (64 senders)** | 2 | **1,503.5/s** | 38.4 ms | 76.0 ms | 96.5 ms | 0% |
| **Peak (64 senders)** | 4 | **1,356.9/s** | 43.1 ms | 76.2 ms | 95.6 ms | 0% |
| Slow destination 2/s | 1 / 2 / 4 | 2.0/s | 10.4–12.1 ms | 13.2–20.5 ms | 13.7–33.0 ms | 0% |
| Burst 100 → 1,000 → 100/s | 1 | 372.8/s avg | 78.8 ms | 2.43 s | 2.66 s | 0% |
| Burst 100 → 1,000 → 100/s | 2 | 370.9/s avg | 115.1 ms | 2.46 s | 2.70 s | 0% |
| Burst 100 → 1,000 → 100/s | 4 | 336.2/s avg | 498.0 ms | 3.12 s | 3.33 s | 0% |

### Delivery

| Scenario | Workers | Deliveries | Delivery rate | e2e p50 | e2e p95 | Backlog drained after load |
|---|---:|---:|---:|---:|---:|---:|
| Drain 10,000 backlog | 1 | 10,000 | **93/s** | 60 s | 105 s | 109 s |
| Drain 10,000 backlog | 2 | 10,000 | **154/s** | 39 s | 66 s | 66 s |
| Drain 10,000 backlog | 4 | 10,000 | **238/s** | 29 s | 43 s | 43 s |
| Steady 300/s (36,000) | 1 | 36,001 | 58/s | 299 s | 444 s | 495 s |
| Steady 300/s (36,000) | 2 | 36,000 | 118/s | 112 s | 177 s | 184 s |
| Steady 300/s (36,000) | 4 | 36,001 | 178/s | 53 s | 80 s | 82 s |
| Slow destination (2 s) | 1 | 120 | 0.5/s | 113 s | 194 s | 202 s |
| Slow destination (2 s) | 2 | 120 | 1.0/s | 34 s | 60 s | 62 s |
| Slow destination (2 s) | 4 | 121 | 1.9/s | 3 s | 4 s | 4 s |

The drain capacity is computed from first to last success, so it excludes the dispatcher's start-up delay: 92.6/s, 153.8/s and 238.1/s respectively.

## What the numbers say

1. **Ingestion stays fast when delivery doesn't keep up.** At a steady 300/s, ingestion p95 was 20–26 ms with every worker count, even with 1 worker and a backlog growing at about 240/s. That's the transactional outbox doing its job: accepting a webhook never waits for delivery.
2. **Peak ingestion is about 1,350–1,600/s, bounded by CPU.** That's 64 PHP threads on 32 vCPUs, plus Postgres commits. More worker containers lower the peak slightly because they compete for the same CPUs; on separate machines they wouldn't.
3. **Delivery scales with workers, but sublinearly:** ×1.66 from 1 to 2 workers, and ×2.6 from 1 to 4. The likely limits are the single dispatcher, the single-threaded Node mock receiver and Postgres. The dispatcher claims 100 deliveries per pass, and `SKIP LOCKED` would let a second dispatcher share the work.
4. **Slow destinations are bounded by worker count.** Each worker sends one request at a time, so a 2-second destination gets exactly `workers ÷ 2 s`: 0.5, 1.0 and 1.9/s. Ingestion is unaffected (p95 ≤ 21 ms). This is the clearest pointer for future work: per-destination concurrency with an async HTTP client, so one slow endpoint doesn't need dozens of worker processes.
5. **A 10× burst past the ingestion ceiling degrades gracefully.** At 1,000/s, alongside delivery work on the same machine, requests queued: p95 rose to 2.4–3.1 s, and k6 couldn't start 8–17% of planned requests because it hit its 2,000-VU cap. But **no request failed and nothing was lost**, and the backlog drained afterwards. A real deployment would put the API on more cores or replicas; ingestion is stateless apart from Postgres.

## The bottleneck the benchmark found

The first benchmark run capped ingestion at **~165 req/s** with the API container using about 24 cores, roughly 140 ms of CPU per request. Profiling showed Postgres at ~20 ms of CPU per request even at light load. The cause: **PHP opened a new database connection on every request.** Each connection forks a Postgres backend and runs a SCRAM-SHA-256 handshake, which is deliberately CPU-expensive (4,096 PBKDF2 rounds) on both client and server. Under concurrency, that became a CPU storm.

**The fix:** persistent PDO connections for the API's FrankenPHP threads (`DB_PERSISTENT=true`), with Postgres `max_connections` raised to 300. Containers now also cache config, routes and events at boot.

**The result:** at 150/s, p95 dropped from 46 ms to 17 ms. At 800/s, the old setup couldn't keep up; the new one holds p95 at 73 ms. Peak throughput went from about 165/s to about 1,500/s. The test suite keeps persistent connections off, because they would make the `SKIP LOCKED` test's two "separate" connections share one session.

## Reproducing

```bash
docker compose up -d --wait
./loadtest/run.sh                                  # all scenarios × 1, 2, 4 workers (~45 min)
WORKERS="2" SCENARIOS="steady drain" ./loadtest/run.sh
docker run --rm --network hookrelay_default -v "$PWD/loadtest:/scripts" \
  grafana/k6:2.3.0 run -e SOURCE=demo /scripts/smoke.js   # the CI smoke test
```

The harness creates its own `bench` and `bench-slow` sources, wipes their events before each scenario, and appends a row per run to `loadtest/results/summary.tsv`.
