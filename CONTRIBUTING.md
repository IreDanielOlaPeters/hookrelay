# Contributing to HookRelay

Thanks for helping. This guide covers the local setup, the checks every change must pass, and the conventions we follow.

## Local setup

You only need Docker (Compose v2) and, for the dashboard tooling, Node 22+. PHP runs inside the containers.

```bash
git clone https://github.com/IreDanielOlaPeters/hookrelay && cd hookrelay
docker compose up -d --build      # api :8080, dashboard :3000, mock receiver :4000
# then browse to http://localhost:3000 (the demo login is pre-filled; see README)
```

For a full-access account, run `docker compose exec api php artisan relay:admin you@example.com --generate`.

To iterate on the API without rebuilding the image, mount the source:

```bash
docker compose run --rm --no-deps -v ./api:/app api composer install
docker compose run --rm --no-deps -v ./api:/app api php artisan test
```

For the dashboard, `cd dashboard && npm ci && API_URL=http://localhost:8080 npm run dev`.

## Checks (all run in CI)

| What | Command |
|---|---|
| API tests (Pest, real Postgres + Redis) | `make test` (also runs the dashboard's Vitest suite) |
| PHP style | `docker compose exec api ./vendor/bin/pint` (CI runs `pint --test`) |
| Dashboard lint, types, tests, build | `cd dashboard && npm run lint && npm run typecheck && npm test && npm run build` |
| Dependency audits | `make audit` (no Composer advisories; no high or critical npm issues) |
| Load smoke (ingestion p95 < 100 ms) | `docker run --rm --network hookrelay_default -v "$PWD/loadtest:/scripts" grafana/k6:2.3.0 run -e SOURCE=demo /scripts/smoke.js` |

The test suite refuses to run against any database whose name doesn't end in `_test`, so it can't wipe your dev data. The full benchmark (`./loadtest/run.sh`) takes about 40 minutes and isn't part of CI. See [BENCHMARKS.md](BENCHMARKS.md).

## Making a change

1. **Open an issue first** for anything bigger than a small fix, so we can agree on the approach.
2. **Write the test first** where you can. Delivery semantics (outbox, `SKIP LOCKED`, retries, breaker) are exactly where regressions hide; [README → Lessons learned](README.md#lessons-learned) has three examples.
3. **Keep ingestion fast and thin.** `POST /in/{slug}` does lookups, verification and one transaction, nothing else. Work that can wait goes to the dispatcher or a job.
4. **Update the docs** that describe what you changed: the README, `docs/api.md`, `SECURITY.md` and `CHANGELOG.md` (under *Unreleased*).

### Commit messages

We use [Conventional Commits](https://www.conventionalcommits.org/): `feat(api): …`, `fix(delivery): …`, `docs: …`, `ci: …`, `build(deps): …`. Mark breaking changes with `!` and a `BREAKING CHANGE:` footer. Keep the subject under about 72 characters, in the imperative mood.

### Pull requests

- One logical change per PR, with green CI.
- Describe *why* as well as what, and how you verified it.
- New config goes in `config/relay.php`, `.env.example`, `docker-compose.yml` and the README configuration table.

## Security issues

Please don't open public issues for vulnerabilities. See [SECURITY.md](SECURITY.md).
