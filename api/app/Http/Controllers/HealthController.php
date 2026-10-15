<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /** Liveness: the PHP process answers. No dependencies, so a DB blip doesn't restart the API. */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** Readiness: Postgres and Redis reachable, schema migrated. 503 otherwise. */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'schema' => $this->check(fn () => DB::table('deliveries')->limit(1)->exists()),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
        ];
        $ok = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json(['status' => $ok ? 'ready' : 'not_ready', 'checks' => $checks], $ok ? 200 : 503);
    }

    /** @return array{ok: bool, error?: string} */
    private function check(callable $fn): array
    {
        try {
            $fn();

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => class_basename($e)];
        }
    }
}
