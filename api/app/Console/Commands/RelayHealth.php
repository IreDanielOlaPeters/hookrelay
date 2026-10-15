<?php

namespace App\Console\Commands;

use App\Services\Observability\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Container healthcheck for processes without HTTP: dependencies reachable and heartbeat fresh. */
class RelayHealth extends Command
{
    protected $signature = 'relay:health {--role= : worker|dispatcher|cron: also require a fresh heartbeat} {--max-age= : Heartbeat age limit in seconds}';

    protected $description = 'Exit 0 when Postgres, Redis and (with --role) this process heartbeat are healthy';

    private const MAX_AGE = ['worker' => 60, 'dispatcher' => 60, 'cron' => 150];

    public function handle(): int
    {
        try {
            DB::select('select 1');
            Redis::connection()->ping();
        } catch (Throwable $e) {
            $this->error('Dependency check failed: '.class_basename($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        if ($role = $this->option('role')) {
            $max = (int) ($this->option('max-age') ?: (self::MAX_AGE[$role] ?? 60));
            $age = Heartbeat::age($role);
            if ($age === null || $age > $max) {
                $this->error("No fresh {$role} heartbeat (age: ".($age ?? 'none')."s, limit {$max}s).");

                return self::FAILURE;
            }
        }

        $this->line('ok');

        return self::SUCCESS;
    }
}
