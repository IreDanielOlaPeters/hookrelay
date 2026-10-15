<?php

use App\Enums\DeliveryStatus;
use App\Models\Connection;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Source;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Delivery\CircuitBreaker;
use App\Support\DemoFixtures;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

function demoHeaders(): array
{
    return sessionHeaders(DemoFixtures::ensureDemoUser());
}

beforeEach(fn () => config(['relay.demo.enabled' => true]));

it('creates the demo user and demo data on boot, idempotently', function () {
    $this->artisan('relay:bootstrap')->assertSuccessful();
    $this->artisan('relay:bootstrap')->assertSuccessful();

    $demo = User::where('email', 'demo@hookrelay.local')->sole();
    expect($demo->isDemo())->toBeTrue()
        ->and(Source::pluck('slug')->sort()->values()->all())->toBe(['demo', 'stripe-test'])
        ->and(Destination::count())->toBe(2);

    $this->postJson('/api/auth/login', ['email' => 'demo@hookrelay.local', 'password' => 'hookrelay-demo'])
        ->assertOk()->assertJsonPath('user.role', 'demo');
});

it('repairs the demo password on boot if it was changed', function () {
    $demo = DemoFixtures::ensureDemoUser();
    $demo->update(['password' => 'something-else']);

    $this->artisan('relay:bootstrap')->assertSuccessful();

    $this->postJson('/api/auth/login', ['email' => 'demo@hookrelay.local', 'password' => 'hookrelay-demo'])->assertOk();
});

it('removes the demo user on boot when DEMO_MODE is off, and refuses demo logins', function () {
    DemoFixtures::ensureDemoUser();
    config(['relay.demo.enabled' => false]);

    $this->postJson('/api/auth/login', ['email' => 'demo@hookrelay.local', 'password' => 'hookrelay-demo'])->assertStatus(422);
    $this->artisan('relay:bootstrap')->assertSuccessful();

    expect(User::where('role', 'demo')->count())->toBe(0);
});

it('publishes the demo credentials only in demo mode', function () {
    $this->getJson('/api/auth/demo')->assertOk()->assertJson([
        'demo_mode' => true, 'email' => 'demo@hookrelay.local', 'password' => 'hookrelay-demo',
    ]);

    config(['relay.demo.enabled' => false]);
    $this->getJson('/api/auth/demo')->assertOk()->assertExactJson(['demo_mode' => false]);
});

it('lets the demo user browse, send test webhooks and replay', function () {
    Http::fake(['*' => Http::response('', 500)]);
    [$source] = sourceWithDestinations(destinationAttributes: ['max_attempts' => 1]);
    $headers = demoHeaders();

    $this->getJson('/api/events', $headers)->assertOk();
    $this->getJson('/api/stats', $headers)->assertOk();
    $this->getJson('/api/auth/me', $headers)->assertJsonPath('user.role', 'demo')->assertJsonPath('demo_mode', true);

    $eventId = $this->postJson("/api/sources/{$source->id}/test", ['body' => '{"hello":"demo"}'], $headers)
        ->assertStatus(202)->json('event_id');
    $event = WebhookEvent::findOrFail($eventId);
    expect($event->body)->toBe('{"hello":"demo"}')
        ->and($event->headers['x-hookrelay-test'][0] ?? null)->not->toBeNull()
        ->and($event->verified)->toBeFalse();

    $delivery = Delivery::sole();
    $delivery->update(['status' => DeliveryStatus::Dead]);
    $this->postJson("/api/deliveries/{$delivery->id}/replay", [], $headers)->assertOk();
    $this->postJson('/api/deliveries/replay', [], $headers)->assertOk();
});

it('sends test webhooks to signed sources without a signature and never dedupes them', function () {
    [$source] = sourceWithDestinations(['provider' => 'stripe', 'signing_secret' => 'whsec_x']);

    $this->postJson("/api/sources/{$source->id}/test", [], demoHeaders())->assertStatus(202);
    $this->postJson("/api/sources/{$source->id}/test", [], demoHeaders())->assertStatus(202);

    expect(WebhookEvent::count())->toBe(2);
});

it('only lets the demo user create destinations on the mock receiver', function () {
    $headers = demoHeaders();

    $this->postJson('/api/destinations', ['name' => 'evil', 'url' => 'https://example.com/hook'], $headers)
        ->assertStatus(422)->assertJsonValidationErrors('url');
    $this->postJson('/api/destinations', ['name' => 'mock', 'url' => 'http://mock-receiver:4000/ok'], $headers)
        ->assertCreated();

    // Admins are not restricted.
    $this->postJson('/api/destinations', ['name' => 'real', 'url' => 'https://example.com/hook'], adminHeaders())->assertCreated();
});

it('forbids the demo user from deleting data or changing settings', function () {
    [$source, [$destination]] = sourceWithDestinations();
    $connection = Connection::sole();
    $headers = demoHeaders();

    $this->deleteJson("/api/sources/{$source->id}", [], $headers)->assertForbidden();
    $this->putJson("/api/sources/{$source->id}", ['name' => 'x'], $headers)->assertForbidden();
    $this->deleteJson("/api/destinations/{$destination->id}", [], $headers)->assertForbidden();
    $this->putJson("/api/destinations/{$destination->id}", ['enabled' => false], $headers)->assertForbidden();
    $this->putJson("/api/connections/{$connection->id}", ['enabled' => false], $headers)->assertForbidden();
    $this->deleteJson("/api/connections/{$connection->id}", [], $headers)->assertForbidden();

    expect(Source::count())->toBe(1)->and($destination->refresh()->enabled)->toBeTrue();

    // A logged-in admin can.
    $this->deleteJson("/api/sources/{$source->id}", [], sessionHeaders(adminUser()))->assertNoContent();
});

it('resets demo data nightly, keeping admins and the demo user', function () {
    $admin = adminUser();
    [$source] = sourceWithDestinations();
    $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"x":1}');
    app(CircuitBreaker::class)->recordFailure(Destination::first()->id);

    $this->artisan('relay:demo-reset')->assertSuccessful();

    expect(WebhookEvent::count())->toBe(0)
        ->and(Delivery::count())->toBe(0)
        ->and(Source::pluck('slug')->sort()->values()->all())->toBe(['demo', 'stripe-test'])
        ->and(User::find($admin->id))->not->toBeNull()
        ->and(User::where('role', 'demo')->count())->toBe(1);
});

it('refuses to reset data when DEMO_MODE is off', function () {
    config(['relay.demo.enabled' => false]);
    [$source] = sourceWithDestinations();

    $this->artisan('relay:demo-reset')->assertFailed();
    expect(Source::count())->toBe(1);
});

it('schedules the nightly reset', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'relay:demo-reset'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 3 * * *');
});
