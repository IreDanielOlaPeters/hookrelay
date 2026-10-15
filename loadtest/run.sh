#!/usr/bin/env bash
# HookRelay benchmark harness. Runs every scenario with 1, 2 and 4 worker containers against
# the local docker compose stack and writes results/*.json plus results/summary.tsv.
#
#   ./loadtest/run.sh                    # everything (~40 min)
#   WORKERS="1" SCENARIOS="steady" ./loadtest/run.sh
#
# Needs: the stack running (docker compose up -d), ADMIN_TOKEN (default dev-admin-token).
set -euo pipefail
cd "$(dirname "$0")/.."

ADMIN_TOKEN=${ADMIN_TOKEN:-dev-admin-token}
API=${API:-http://localhost:8080}
K6_IMAGE=${K6_IMAGE:-grafana/k6:2.3.0}
NETWORK=${NETWORK:-hookrelay_default}
WORKERS=${WORKERS:-"1 2 4"}
SCENARIOS=${SCENARIOS:-"steady burst slow drain"}
OUT=loadtest/results
mkdir -p "$OUT"
SUMMARY="$OUT/summary.tsv"
[ -f "$SUMMARY" ] || printf 'scenario\tworkers\tk6_rps\tingest_p50_ms\tingest_p95_ms\tingest_p99_ms\tingest_fail_rate\tdeliveries\tsucceeded\tdelivery_rps\te2e_p50_s\te2e_p95_s\tdrain_s\n' > "$SUMMARY"

H=(-H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json')
psql() { docker compose exec -T postgres psql -U hookrelay -d hookrelay -tAc "$1"; }

# --- fixtures: bench -> fast destination, bench-slow -> 2 s destination -------------------
ensure_source() { # slug name
  curl -sf "${H[@]}" "$API/api/sources/$(psql "select id from sources where slug='$1'")" >/dev/null 2>&1 \
    || curl -sf "${H[@]}" -X POST "$API/api/sources" -d "{\"name\":\"$2\",\"slug\":\"$1\"}" >/dev/null
  psql "select id from sources where slug='$1'"
}
ensure_destination() { # name url
  local id; id=$(psql "select id from destinations where name='$1'")
  [ -n "$id" ] || curl -sf "${H[@]}" -X POST "$API/api/destinations" -d "{\"name\":\"$1\",\"url\":\"$2\",\"max_attempts\":6}" >/dev/null
  psql "select id from destinations where name='$1'"
}
connect() { psql "insert into connections (source_id, destination_id, enabled, created_at, updated_at) values ('$1','$2',true,now(),now()) on conflict do nothing" >/dev/null; }

SRC_FAST=$(ensure_source bench "Benchmark")
SRC_SLOW=$(ensure_source bench-slow "Benchmark (slow destination)")
DST_FAST=$(ensure_destination "Bench: fast" "http://mock-receiver:4000/ok")
DST_SLOW=$(ensure_destination "Bench: 2 s" "http://mock-receiver:4000/any?delay_ms=2000")
connect "$SRC_FAST" "$DST_FAST"; connect "$SRC_SLOW" "$DST_SLOW"

reset_data() {
  psql "delete from events where source_id in ('$SRC_FAST','$SRC_SLOW')" >/dev/null
  docker compose exec -T redis redis-cli DEL queues:deliveries queues:deliveries:delayed queues:deliveries:reserved >/dev/null
}

k6() { # script name [env...]
  local script=$1 name=$2; shift 2
  docker run --rm -u "$(id -u):$(id -g)" --network "$NETWORK" -v "$PWD/loadtest:/scripts" "$K6_IMAGE" run --quiet \
    --summary-export "/scripts/results/$name.json" "$@" "/scripts/$script" >/dev/null 2>"$OUT/$name.log" || true
}

wait_drained() { # source_id timeout_s -> seconds waited
  local start=$SECONDS
  while (( SECONDS - start < $2 )); do
    [ "$(psql "select count(*) from deliveries d join events e on e.id=d.event_id where e.source_id='$1' and d.status in ('pending','delivering')")" = "0" ] && break
    sleep 1
  done
  echo $(( SECONDS - start ))
}

record() { # scenario workers source_id k6name drain_s
  local f="$OUT/$4.json"
  read -r rps p50 p95 p99 fail < <(python3 - "$f" <<'PY'
import json,sys
m=json.load(open(sys.argv[1]))["metrics"]
d=m.get("http_req_duration{name:ingest}") or m["http_req_duration"]
print(round(m["http_reqs"]["rate"],1), round(d["med"],1), round(d["p(95)"],1), round(d["p(99)"],1), round(m["http_req_failed"]["value"],4))
PY
)
  read -r n ok drps e50 e95 < <(psql "
    select count(*), count(*) filter (where d.status='succeeded'),
           round((count(*) filter (where d.status='succeeded') / nullif(extract(epoch from max(d.updated_at) filter (where d.status='succeeded') - min(e.received_at)),0))::numeric, 1),
           round(percentile_cont(0.5) within group (order by extract(epoch from d.updated_at - e.received_at)) filter (where d.status='succeeded')::numeric, 2),
           round(percentile_cont(0.95) within group (order by extract(epoch from d.updated_at - e.received_at)) filter (where d.status='succeeded')::numeric, 2)
      from deliveries d join events e on e.id = d.event_id where e.source_id='$3'" | tr '|' ' ')
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$1" "$2" "$rps" "$p50" "$p95" "$p99" "$fail" "$n" "$ok" "$drps" "$e50" "$e95" "$5" | tee -a "$SUMMARY"
}

for w in $WORKERS; do
  echo "== $w worker(s)"
  docker compose up -d --scale worker="$w" --no-recreate worker >/dev/null 2>&1
  sleep 5

  for s in $SCENARIOS; do
    reset_data
    case $s in
      steady) k6 steady.js "steady-w$w" -e SOURCE=bench -e RATE=300 -e DURATION=2m
              record steady "$w" "$SRC_FAST" "steady-w$w" "$(wait_drained "$SRC_FAST" 900)" ;;
      burst)  k6 burst.js "burst-w$w" -e SOURCE=bench -e RATE=100
              record burst "$w" "$SRC_FAST" "burst-w$w" "$(wait_drained "$SRC_FAST" 1800)" ;;
      slow)   k6 slow.js "slow-w$w" -e SOURCE=bench-slow -e RATE=2 -e DURATION=60s
              record slow "$w" "$SRC_SLOW" "slow-w$w" "$(wait_drained "$SRC_SLOW" 900)" ;;
      drain)  # Peak ingestion with the dispatcher paused, then pure delivery capacity on the backlog.
              docker compose stop scheduler >/dev/null 2>&1
              k6 max.js "drain-w$w" -e SOURCE=bench -e ITERATIONS=10000 -e VUS=64
              docker compose start scheduler >/dev/null 2>&1
              t0=$SECONDS; drained=$(wait_drained "$SRC_FAST" 1800)
              # Delivery rate from dispatcher start, not from first ingest:
              rate=$(psql "select round((count(*) / nullif(extract(epoch from max(d.updated_at) - min(d.updated_at)),0))::numeric,1) from deliveries d join events e on e.id=d.event_id where e.source_id='$SRC_FAST' and d.status='succeeded'")
              record drain "$w" "$SRC_FAST" "drain-w$w" "$drained"
              printf 'drain\t%s\tdelivery capacity (first->last success)\t%s/s\n' "$w" "$rate" | tee -a "$OUT/capacity.tsv" ;;
    esac
  done
done

docker compose up -d --scale worker="${WORKER_REPLICAS:-2}" --no-recreate worker >/dev/null 2>&1
echo "Results in $SUMMARY"
