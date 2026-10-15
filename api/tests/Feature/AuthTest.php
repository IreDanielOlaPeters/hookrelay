<?php

use App\Models\User;
use App\Models\UserSession;
use App\Support\DemoFixtures;
use Illuminate\Support\Carbon;

it('logs in with valid credentials and returns a session token that authenticates', function () {
    adminUser();

    $login = $this->postJson('/api/auth/login', ['email' => 'Admin@Example.test ', 'password' => 'correct-horse-battery'])
        ->assertOk()
        ->assertJsonPath('user.email', 'admin@example.test')
        ->assertJsonPath('user.role', 'admin')
        ->assertJsonStructure(['token', 'expires_at']);

    $headers = ['Authorization' => 'Bearer '.$login->json('token'), 'Accept' => 'application/json'];
    $this->getJson('/api/auth/me', $headers)->assertOk()->assertJsonPath('user.role', 'admin')->assertJsonPath('demo_mode', false);
    $this->getJson('/api/sources', $headers)->assertOk();

    // Only a hash of the token is stored.
    expect(UserSession::sole()->token_hash)->toBe(hash('sha256', $login->json('token')));
});

it('rejects bad credentials with the same message for unknown emails and wrong passwords', function () {
    adminUser();

    $wrong = $this->postJson('/api/auth/login', ['email' => 'admin@example.test', 'password' => 'nope'])->assertStatus(422);
    $unknown = $this->postJson('/api/auth/login', ['email' => 'who@example.test', 'password' => 'nope'])->assertStatus(422);

    expect($wrong->json('message'))->toBe($unknown->json('message'))
        ->and(UserSession::count())->toBe(0);
});

it('throttles repeated login attempts', function () {
    adminUser();
    foreach (range(1, 10) as $_) {
        $this->postJson('/api/auth/login', ['email' => 'admin@example.test', 'password' => 'nope'])->assertStatus(422);
    }

    $this->postJson('/api/auth/login', ['email' => 'admin@example.test', 'password' => 'correct-horse-battery'])->assertStatus(429);
});

it('does not throttle the public demo account per email, so many visitors can sign in', function () {
    config(['relay.demo.enabled' => true]);
    DemoFixtures::ensureDemoUser();

    foreach (range(1, 15) as $_) {
        $this->postJson('/api/auth/login', ['email' => 'demo@hookrelay.local', 'password' => 'hookrelay-demo'])->assertOk();
    }
});

it('caps login attempts per client IP across emails', function () {
    foreach (range(1, 120) as $i) {
        $this->postJson('/api/auth/login', ['email' => "user{$i}@example.test", 'password' => 'x'])->assertStatus(422);
    }

    $this->postJson('/api/auth/login', ['email' => 'another@example.test', 'password' => 'x'])->assertStatus(429);
});

it('logs out by deleting the session', function () {
    $headers = sessionHeaders(adminUser());

    $this->postJson('/api/auth/logout', [], $headers)->assertNoContent();
    $this->getJson('/api/auth/me', $headers)->assertUnauthorized();
});

it('rejects expired sessions and unknown tokens', function () {
    $headers = sessionHeaders(adminUser());
    $this->travel(config('relay.session_minutes') + 1)->minutes();

    $this->getJson('/api/events', $headers)->assertUnauthorized();
    $this->getJson('/api/events', ['Authorization' => 'Bearer not-a-real-token', 'Accept' => 'application/json'])->assertUnauthorized();
    $this->getJson('/api/events')->assertUnauthorized();
});

it('still accepts the static ADMIN_TOKEN as an admin', function () {
    $this->getJson('/api/auth/me', adminHeaders())->assertOk()->assertJsonPath('user.role', 'admin');
});

it('creates and updates the admin with relay:admin and signs out existing sessions', function () {
    $this->artisan('relay:admin', ['email' => 'Boss@Example.test', '--password' => 'a-very-long-password'])
        ->expectsOutputToContain('Created admin boss@example.test')
        ->assertSuccessful();
    $user = User::where('email', 'boss@example.test')->sole();
    expect($user->isAdmin())->toBeTrue();
    $headers = sessionHeaders($user);

    $this->artisan('relay:admin', ['email' => 'boss@example.test', '--password' => 'another-long-password'])
        ->expectsOutputToContain('Updated admin')
        ->assertSuccessful();

    $this->getJson('/api/auth/me', $headers)->assertUnauthorized();
    $this->postJson('/api/auth/login', ['email' => 'boss@example.test', 'password' => 'another-long-password'])->assertOk();
});

it('rejects weak passwords and the reserved demo address in relay:admin', function () {
    $this->artisan('relay:admin', ['email' => 'boss@example.test', '--password' => 'short'])->assertFailed();
    $this->artisan('relay:admin', ['email' => 'demo@hookrelay.local', '--password' => 'a-very-long-password'])->assertFailed();
    $this->artisan('relay:admin', ['email' => 'boss@example.test', '--generate' => true])
        ->expectsOutputToContain('Password: ')
        ->assertSuccessful();

    expect(User::count())->toBe(1);
});

it('stores session expiry in the configured window', function () {
    Carbon::setTestNow('2026-10-16 12:00:00');
    [$session] = UserSession::start(adminUser());

    expect($session->expires_at->toDateTimeString())->toBe('2026-10-17 00:00:00');
});
