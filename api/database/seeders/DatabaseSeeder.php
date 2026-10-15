<?php

namespace Database\Seeders;

use App\Support\DemoFixtures;
use Illuminate\Database\Seeder;

/** `make seed`: the demo sources and destinations. Idempotent. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DemoFixtures::seed();

        $this->command?->info('Seeded sources: demo, stripe-test (secret: '.DemoFixtures::stripeSecret().')');
        $this->command?->info('Seeded destinations: mock-receiver /ok and /flaky');
    }
}
