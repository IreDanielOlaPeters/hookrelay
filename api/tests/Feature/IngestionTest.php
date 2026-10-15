<?php

use App\Enums\DeliveryStatus;
use App\Enums\Provider;
use App\Models\Connection;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;

it('stores the event and one pending delivery per enabled connection, returning 202', function () {
    [$source, $destinations] = sourceWithDestinations(destinations: 2);
    // A disabled connection gets no delivery.
    $disabled = Destination::create(['name' => 'off', 'url' => 'https://off.test']);
    Connection::create(['source_id' => $source->id, 'destination_id' => $disabled->id, 'enabled' => false]);

    $response = $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"hello":"world"}');

    $response->assertStatus(202)->assertJsonStructure(['event_id'])->assertJson(['deliveries' => 2]);

    $event = WebhookEvent::findOrFail($response->json('event_id'));
    expect($event->body)->toBe('{"hello":"world"}')
        ->and($event->content_type)->toBe('application/json')
        ->and($event->verified)->toBeFalse()
        ->and($event->idempotency_key)->toBe('sha256:'.hash('sha256', '{"hello":"world"}'));

    $deliveries = Delivery::where('event_id', $event->id)->get();
    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('destination_id')->sort()->values()->all())
        ->toBe(collect($destinations)->pluck('id')->sort()->values()->all())
        ->and($deliveries->every(fn ($d) => $d->status === DeliveryStatus::Pending && $d->next_attempt_at !== null))->toBeTrue();
});

it('stores the raw body byte-for-byte', function () {
    [$source] = sourceWithDestinations();
    $raw = "{\n  \"spacing\" :  \"kept\",\t\"unicode\": \"caf\u{e9} \u{1F680}\"\n}\n";

    $id = $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->json('event_id');

    expect(WebhookEvent::find($id)->rawBody())->toBe($raw);
});

it('keeps non-UTF-8 bodies byte-exact via base64', function () {
    [$source] = sourceWithDestinations();
    $raw = "\x00\xff\xfe binary \x80";

    $id = $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $raw)
        ->assertStatus(202)->json('event_id');

    $event = WebhookEvent::find($id);
    expect($event->body_encoding)->toBe('base64')->and($event->rawBody())->toBe($raw);
});

it('creates nothing for a duplicate idempotency key and returns the original event id', function () {
    [$source] = sourceWithDestinations(destinations: 2);
    $send = fn () => $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"same":"payload"}');

    $first = $send()->assertStatus(202);
    $second = $send()->assertStatus(200)->assertJson(['duplicate' => true]);

    expect($second->json('event_id'))->toBe($first->json('event_id'))
        ->and(WebhookEvent::count())->toBe(1)
        ->and(Delivery::count())->toBe(2);
});

it('uses the provider event id as the idempotency key', function (Provider $provider, array $headers, string $body, string $expectedKey) {
    [$source] = sourceWithDestinations(['provider' => $provider]);
    $server = ['CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $first = $this->call('POST', "/in/{$source->slug}", [], [], [], $server, $body)->assertStatus(202);
    // Same provider id, different body (e.g. a retry with a new timestamp) is still a duplicate.
    $this->call('POST', "/in/{$source->slug}", [], [], [], $server, $body.' ')->assertStatus(200);

    expect(WebhookEvent::find($first->json('event_id'))->idempotency_key)->toBe($expectedKey)
        ->and(WebhookEvent::count())->toBe(1);
})->with([
    'stripe' => [Provider::Stripe, [], '{"id":"evt_1","object":"event"}', 'id:evt_1'],
    'github' => [Provider::GitHub, ['X-GitHub-Delivery' => 'gh-123'], '{"zen":"hi"}', 'id:gh-123'],
    'shopify' => [Provider::Shopify, ['X-Shopify-Webhook-Id' => 'shop-9'], '{"order":1}', 'id:shop-9'],
    'woocommerce' => [Provider::WooCommerce, ['X-WC-Webhook-Delivery-ID' => 'wc-7'], '{"order":2}', 'id:wc-7'],
    'generic Idempotency-Key' => [Provider::Generic, ['Idempotency-Key' => 'abc'], '{"x":1}', 'id:abc'],
]);

it('accepts the same payload again once the dedupe window has passed', function () {
    [$source] = sourceWithDestinations(['dedupe_window_seconds' => 60]);
    $send = fn () => $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"ping":1}');

    $first = $send()->assertStatus(202)->json('event_id');
    $this->travel(61)->seconds();
    $second = $send()->assertStatus(202)->json('event_id');

    expect($second)->not->toBe($first)->and(WebhookEvent::count())->toBe(2);
});

it('is atomic: if writing a delivery fails, the event is not stored either', function () {
    [$source] = sourceWithDestinations(destinations: 2);
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION fail_delivery_insert() RETURNS trigger AS $$
        BEGIN RAISE EXCEPTION 'simulated outbox failure'; END; $$ LANGUAGE plpgsql;
        CREATE TRIGGER fail_delivery_insert BEFORE INSERT ON deliveries
        FOR EACH ROW EXECUTE FUNCTION fail_delivery_insert();
    SQL);

    $this->withoutExceptionHandling();
    expect(fn () => $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":1}'))
        ->toThrow(Illuminate\Database\QueryException::class, 'simulated outbox failure');

    expect(WebhookEvent::count())->toBe(0)->and(Delivery::count())->toBe(0);
});

it('returns 401 and stores nothing for a bad signature', function () {
    [$source] = sourceWithDestinations(['provider' => Provider::GitHub, 'signing_secret' => 'gh-secret']);
    $body = '{"action":"opened"}';

    $this->call('POST', "/in/{$source->slug}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wrong-secret'),
    ], $body)->assertStatus(401);

    $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(401);

    expect(WebhookEvent::count())->toBe(0)->and(Delivery::count())->toBe(0);
});

it('marks events with a valid signature as verified', function () {
    [$source] = sourceWithDestinations(['provider' => Provider::Stripe, 'signing_secret' => 'whsec_x']);
    $body = '{"id":"evt_ok","type":"charge.succeeded"}';
    $t = time();

    $id = $this->call('POST', "/in/{$source->slug}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", 'whsec_x'),
    ], $body)->assertStatus(202)->json('event_id');

    expect(WebhookEvent::find($id)->verified)->toBeTrue();
});

it('returns 404 for an unknown source', function () {
    $this->postJson('/in/nope', ['a' => 1])->assertStatus(404);
});
