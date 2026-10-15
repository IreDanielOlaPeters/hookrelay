<?php

use App\Models\Connection;
use App\Models\Destination;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Security\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Redis::connection()->flushdb();
        // No real DNS in tests: every host resolves to a public address unless a test says otherwise.
        app()->instance(DnsResolver::class, new FakeDnsResolver);
    })
    ->in('Feature');

/** A source connected to the given destinations (or one fresh destination). */
function sourceWithDestinations(array $sourceAttributes = [], int $destinations = 1, array $destinationAttributes = []): array
{
    $source = Source::create(array_merge([
        'name' => 'Test source',
        'slug' => 'src-'.fake()->unique()->lexify('??????'),
    ], $sourceAttributes));

    $created = [];
    for ($i = 0; $i < $destinations; $i++) {
        $created[] = $destination = Destination::create(array_merge([
            'name' => "Destination {$i}",
            'url' => "https://receiver.test/hook{$i}",
        ], $destinationAttributes));
        Connection::create(['source_id' => $source->id, 'destination_id' => $destination->id]);
    }

    return [$source, $created];
}

function adminHeaders(): array
{
    return ['Authorization' => 'Bearer test-admin-token', 'Accept' => 'application/json'];
}

function adminUser(string $email = 'admin@example.test', string $password = 'correct-horse-battery'): User
{
    $user = new User(['name' => 'Admin', 'email' => $email, 'password' => $password]);
    $user->role = User::ROLE_ADMIN;
    $user->save();

    return $user;
}

/** Bearer headers for a fresh dashboard session of the given user. */
function sessionHeaders(User $user): array
{
    [, $token] = UserSession::start($user);

    return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
}

/** Make the given hosts resolve to the given IPs for the rest of the test. */
function fakeDns(array $records, ?string $default = '93.184.216.34'): FakeDnsResolver
{
    $resolver = new FakeDnsResolver($records, $default);
    app()->instance(DnsResolver::class, $resolver);

    return $resolver;
}
