<?php

namespace Database\Seeders;

use App\Enums\Provider;
use App\Models\Connection;
use App\Models\Destination;
use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Demo setup: two sources fanning out to two mock-receiver destinations.
 * Idempotent, so `make seed` can be run repeatedly.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $receiver = rtrim(env('MOCK_RECEIVER_URL', 'http://mock-receiver:4000'), '/');

        $sources = [
            Source::updateOrCreate(['slug' => 'demo'], [
                'name' => 'Demo (generic)',
                'provider' => Provider::Generic,
                'signing_secret' => null,
            ]),
            Source::updateOrCreate(['slug' => 'stripe-test'], [
                'name' => 'Stripe (test)',
                'provider' => Provider::Stripe,
                'signing_secret' => env('SEED_STRIPE_SECRET', 'whsec_test_hookrelay_demo'),
            ]),
        ];

        $destinations = [
            Destination::updateOrCreate(['name' => 'Mock receiver (ok)'], [
                'url' => "{$receiver}/ok",
                'enabled' => true,
            ]),
            Destination::updateOrCreate(['name' => 'Mock receiver (flaky)'], [
                'url' => "{$receiver}/flaky",
                'enabled' => true,
            ]),
        ];

        foreach ($sources as $source) {
            foreach ($destinations as $destination) {
                Connection::updateOrCreate(
                    ['source_id' => $source->id, 'destination_id' => $destination->id],
                    ['enabled' => true],
                );
            }
        }

        $this->command?->info('Seeded sources: demo, stripe-test (secret: '.env('SEED_STRIPE_SECRET', 'whsec_test_hookrelay_demo').')');
        $this->command?->info("Seeded destinations: {$receiver}/ok, {$receiver}/flaky");
    }
}
