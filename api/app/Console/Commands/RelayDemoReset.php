<?php

namespace App\Console\Commands;

use App\Services\Delivery\CircuitBreaker;
use App\Services\Delivery\TokenBucket;
use App\Support\DemoFixtures;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Nightly in DEMO_MODE: wipe all relay data and restore the demo fixtures. Admin users are kept. */
class RelayDemoReset extends Command
{
    protected $signature = 'relay:demo-reset {--force : Run even when DEMO_MODE is off}';

    protected $description = 'Reset demo data (events, deliveries, sources, destinations) to the demo fixtures';

    public function handle(): int
    {
        if (! config('relay.demo.enabled') && ! $this->option('force')) {
            $this->error('DEMO_MODE is off; refusing to wipe data. Use --force if you really mean it.');

            return self::FAILURE;
        }

        // Clear circuit breakers and token buckets of the destinations about to be deleted.
        foreach (DB::table('destinations')->pluck('id') as $id) {
            app(CircuitBreaker::class)->reset($id);
            app(TokenBucket::class)->reset($id);
        }

        DB::transaction(function () {
            // Cascades remove deliveries, attempts and connections.
            DB::table('events')->delete();
            DB::table('connections')->delete();
            DB::table('sources')->delete();
            DB::table('destinations')->delete();
        });

        DemoFixtures::seed();
        DemoFixtures::ensureDemoUser();

        $this->info('Demo data reset.');

        return self::SUCCESS;
    }
}
