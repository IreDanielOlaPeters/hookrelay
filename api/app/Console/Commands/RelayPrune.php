<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deletes old events (and, by cascade, their deliveries and attempts) in batches.
 *
 * Never deletes an event that still has an undelivered delivery (pending, delivering, failed
 * or dead), and never before its source's dedupe window has passed, so a provider retry can't
 * slip through as a new event after its original was pruned.
 */
class RelayPrune extends Command
{
    protected $signature = 'relay:prune
        {--days= : Retention in days (default RELAY_RETENTION_DAYS, 7)}
        {--batch=1000 : Events deleted per statement}
        {--dry-run : Only count what would be deleted}';

    protected $description = 'Delete delivered events older than the retention period, in batches';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('relay.retention_days'));
        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }
        $batch = max(1, (int) $this->option('batch'));
        $cutoff = Carbon::now()->subDays($days);

        $eligible = <<<'SQL'
            SELECT e.id
              FROM events e
              JOIN sources s ON s.id = e.source_id
             WHERE e.received_at < ?::timestamptz
               AND e.received_at < ?::timestamptz - make_interval(secs => s.dedupe_window_seconds)
               AND NOT EXISTS (
                     SELECT 1 FROM deliveries d
                      WHERE d.event_id = e.id AND d.status <> 'succeeded'
               )
        SQL;
        $now = Carbon::now();

        if ($this->option('dry-run')) {
            $count = DB::selectOne("SELECT count(*) AS n FROM ({$eligible}) x", [$cutoff, $now])->n;
            $this->info("Would delete {$count} events older than {$days} days.");

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $n = DB::delete("DELETE FROM events WHERE id IN ({$eligible} LIMIT ?)", [$cutoff, $now, $batch]);
            $deleted += $n;
        } while ($n === $batch);

        $sessions = DB::table('user_sessions')->where('expires_at', '<', $now)->delete();
        $batches = DB::table('replay_batches')->whereIn('status', ['completed', 'failed'])->where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} events older than {$days} days, {$sessions} expired sessions and {$batches} old replay batches.");

        return self::SUCCESS;
    }
}
