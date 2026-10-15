<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $since = Carbon::now()->subDay();

        $byStatus = DB::table('deliveries')
            ->where('created_at', '>=', $since)
            ->groupBy('status')
            ->selectRaw('status, count(*) AS total')
            ->pluck('total', 'status');

        $deliveries = [];
        foreach (DeliveryStatus::cases() as $status) {
            $deliveries[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }
        $deliveries['total'] = array_sum($deliveries);

        $attempt = DB::selectOne(<<<'SQL'
            SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY duration_ms) AS p50,
                   percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms) AS p95
              FROM attempts
             WHERE started_at >= ?
        SQL, [$since]);

        // End-to-end: event received -> delivery succeeded.
        $endToEnd = DB::selectOne(<<<'SQL'
            SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY ms) AS p50,
                   percentile_cont(0.95) WITHIN GROUP (ORDER BY ms) AS p95
              FROM (
                SELECT EXTRACT(EPOCH FROM (d.updated_at - e.received_at)) * 1000 AS ms
                  FROM deliveries d
                  JOIN events e ON e.id = d.event_id
                 WHERE d.status = 'succeeded' AND d.created_at >= ?
              ) t
        SQL, [$since]);

        return response()->json([
            'window_hours' => 24,
            'events_received' => DB::table('events')->where('received_at', '>=', $since)->count(),
            'deliveries' => $deliveries,
            'latency_ms' => [
                'attempt' => ['p50' => $this->round($attempt->p50), 'p95' => $this->round($attempt->p95)],
                'end_to_end' => ['p50' => $this->round($endToEnd->p50), 'p95' => $this->round($endToEnd->p95)],
            ],
        ]);
    }

    private function round(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }
}
