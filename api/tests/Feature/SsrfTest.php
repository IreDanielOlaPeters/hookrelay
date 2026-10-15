<?php

use App\Enums\DeliveryStatus;
use App\Models\Attempt;
use App\Models\Delivery;
use App\Services\Delivery\Deliverer;
use Illuminate\Support\Facades\Http;

it('rejects destinations pointing at internal addresses when they are saved', function (string $url) {
    $this->postJson('/api/destinations', ['name' => 'x', 'url' => $url], adminHeaders())
        ->assertStatus(422)->assertJsonValidationErrors('url');
})->with(['http://169.254.169.254/latest/meta-data/', 'http://localhost:8080/', 'http://10.0.0.8/hook', 'http://[::1]/']);

it('rejects hosts that resolve to internal addresses when saved, and on update', function () {
    fakeDns(['internal.example' => ['192.168.1.20']]);

    $this->postJson('/api/destinations', ['name' => 'x', 'url' => 'https://internal.example/hook'], adminHeaders())
        ->assertStatus(422)->assertJsonPath('errors.url.0', fn ($m) => str_contains($m, 'internal.example resolves to 192.168.1.20'));

    $id = $this->postJson('/api/destinations', ['name' => 'ok', 'url' => 'https://public.example/hook'], adminHeaders())->assertCreated()->json('data.id');
    $this->putJson("/api/destinations/{$id}", ['url' => 'https://internal.example/hook'], adminHeaders())->assertStatus(422);
});

it('allows internal targets listed in RELAY_SSRF_ALLOWLIST', function () {
    config(['relay.ssrf_allowlist' => ['mock-receiver', '10.20.0.0/16']]);

    $this->postJson('/api/destinations', ['name' => 'mock', 'url' => 'http://mock-receiver:4000/ok'], adminHeaders())->assertCreated();
    $this->postJson('/api/destinations', ['name' => 'lan', 'url' => 'http://10.20.1.1/hook'], adminHeaders())->assertCreated();
    $this->postJson('/api/destinations', ['name' => 'no', 'url' => 'http://10.21.1.1/hook'], adminHeaders())->assertStatus(422);
});

it('re-checks at delivery time: a host that now resolves internally is blocked without sending', function () {
    Http::fake();
    [$source] = sourceWithDestinations(destinationAttributes: ['url' => 'https://rebind.example/hook']);
    $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":1}');
    $delivery = Delivery::sole();
    $delivery->update(['status' => DeliveryStatus::Delivering]);

    // DNS changed after the destination was saved (DNS rebinding).
    fakeDns(['rebind.example' => ['169.254.169.254']]);
    app(Deliverer::class)->deliver($delivery->id);

    Http::assertNothingSent();
    expect(Attempt::sole()->error)->toContain('Blocked by SSRF guard')->toContain('169.254.169.254')
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempt_count)->toBe(1);
});

it('does not follow redirects, so a 302 to a metadata address is just a failed attempt', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);
    [$source] = sourceWithDestinations();
    $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":1}');
    $delivery = Delivery::sole();
    $delivery->update(['status' => DeliveryStatus::Delivering]);

    app(Deliverer::class)->deliver($delivery->id);

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254.169.254'));
    expect(Attempt::sole()->error)->toBe('HTTP 302');
});
