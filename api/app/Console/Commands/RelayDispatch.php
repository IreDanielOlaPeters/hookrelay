<?php

namespace App\Console\Commands;

use App\Services\Delivery\Dispatcher;
use Illuminate\Console\Command;

class RelayDispatch extends Command
{
    protected $signature = 'relay:dispatch
        {--once : Run a single dispatch pass and exit}
        {--drain : Keep dispatching until nothing is due, then exit}
        {--limit= : Max deliveries claimed per pass}
        {--interval= : Seconds to sleep when there is nothing due}';

    protected $description = 'Move due deliveries from the Postgres outbox to the Redis queue (safe to run in parallel)';

    private bool $stopping = false;

    public function handle(Dispatcher $dispatcher): int
    {
        $limit = (int) ($this->option('limit') ?: config('relay.dispatcher.batch_size'));
        $interval = (float) ($this->option('interval') ?: config('relay.dispatcher.interval_seconds'));
        $stuckAfter = (int) config('relay.dispatcher.stuck_after_seconds');

        $this->recover($dispatcher, $stuckAfter);

        if ($this->option('once') || $this->option('drain')) {
            $total = 0;
            do {
                $total += $count = $dispatcher->dispatchDue($limit);
            } while ($this->option('drain') && $count > 0);
            $this->line("Dispatched {$total}");

            return self::SUCCESS;
        }

        $this->trap([SIGTERM, SIGINT], fn () => $this->stopping = true);
        $this->info("Dispatcher running (batch {$limit}, idle interval {$interval}s)");

        $lastRecovery = time();
        while (! $this->stopping) {
            $count = $dispatcher->dispatchDue($limit);
            if ($count > 0) {
                $this->line(now()->toDateTimeString()." dispatched {$count}");
            }

            if (time() - $lastRecovery >= 60) {
                $this->recover($dispatcher, $stuckAfter);
                $lastRecovery = time();
            }

            // A full batch means more is probably due: go again straight away.
            if ($count < $limit && ! $this->stopping) {
                usleep((int) ($interval * 1_000_000));
            }
        }

        $this->info('Dispatcher stopped');

        return self::SUCCESS;
    }

    private function recover(Dispatcher $dispatcher, int $stuckAfter): void
    {
        $recovered = $dispatcher->recoverStuck($stuckAfter);
        if ($recovered > 0) {
            $this->warn("Recovered {$recovered} stuck deliveries");
        }
    }
}
