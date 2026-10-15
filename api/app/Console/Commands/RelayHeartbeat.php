<?php

namespace App\Console\Commands;

use App\Services\Observability\Heartbeat;
use Illuminate\Console\Command;

class RelayHeartbeat extends Command
{
    protected $signature = 'relay:heartbeat {role}';

    protected $description = 'Record a heartbeat for a role (scheduled every minute for the cron service)';

    public function handle(): int
    {
        Heartbeat::beat((string) $this->argument('role'), everySeconds: 0);

        return self::SUCCESS;
    }
}
