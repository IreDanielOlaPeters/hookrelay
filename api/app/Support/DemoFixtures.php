<?php

namespace App\Support;

use App\Enums\Provider;
use App\Models\Connection;
use App\Models\Destination;
use App\Models\Source;
use App\Models\User;

/**
 * The demo setup: two sources fanning out to two mock-receiver destinations, plus the
 * restricted demo user. Idempotent. Used by `make seed`, boot (DEMO_MODE) and the nightly reset.
 */
class DemoFixtures
{
    public static function seed(): void
    {
        $receiver = rtrim((string) config('relay.demo.mock_receiver_url'), '/');

        $sources = [
            Source::updateOrCreate(['slug' => 'demo'], [
                'name' => 'Demo (generic)',
                'provider' => Provider::Generic,
                'signing_secret' => null,
            ]),
            Source::updateOrCreate(['slug' => 'stripe-test'], [
                'name' => 'Stripe (test)',
                'provider' => Provider::Stripe,
                'signing_secret' => self::stripeSecret(),
            ]),
        ];

        $destinations = [
            Destination::updateOrCreate(['name' => 'Mock receiver (ok)'], ['url' => "{$receiver}/ok", 'enabled' => true]),
            Destination::updateOrCreate(['name' => 'Mock receiver (flaky)'], ['url' => "{$receiver}/flaky", 'enabled' => true]),
        ];

        foreach ($sources as $source) {
            foreach ($destinations as $destination) {
                Connection::updateOrCreate(
                    ['source_id' => $source->id, 'destination_id' => $destination->id],
                    ['enabled' => true],
                );
            }
        }
    }

    /** Create or repair the demo user so the published credentials always work. */
    public static function ensureDemoUser(): User
    {
        $user = User::firstOrNew(['email' => config('relay.demo.email')]);
        $user->fill(['name' => 'Demo user', 'password' => config('relay.demo.password')]);
        $user->role = User::ROLE_DEMO;
        $user->save();

        return $user;
    }

    /** Outside demo mode there must be no account with publicly known credentials. */
    public static function removeDemoUsers(): int
    {
        return User::query()->where('role', User::ROLE_DEMO)->delete();
    }

    public static function stripeSecret(): string
    {
        return (string) config('relay.demo.stripe_secret');
    }
}
