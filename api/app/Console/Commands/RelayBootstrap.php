<?php

namespace App\Console\Commands;

use App\Support\DemoFixtures;
use Illuminate\Console\Command;

/** Runs on every API boot, after migrations. Idempotent. */
class RelayBootstrap extends Command
{
    protected $signature = 'relay:bootstrap';

    protected $description = 'Boot-time setup: ensure the demo user and demo data in DEMO_MODE, remove the demo user otherwise';

    public function handle(): int
    {
        if (config('relay.demo.enabled')) {
            DemoFixtures::ensureDemoUser();
            DemoFixtures::seed();
            $this->info('Demo mode: demo user '.config('relay.demo.email').' and demo data are in place.');
        } elseif (($removed = DemoFixtures::removeDemoUsers()) > 0) {
            $this->warn("DEMO_MODE is off: removed {$removed} demo user(s).");
        }

        return self::SUCCESS;
    }
}
