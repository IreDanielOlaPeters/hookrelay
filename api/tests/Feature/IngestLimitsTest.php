<?php

use App\Models\WebhookEvent;

function post(string $slug, string $body, array $server = [])
{
    return test()->call('POST', "/in/{$slug}", [], [], [], array_merge(['CONTENT_TYPE' => 'application/json'], $server), $body);
}

it('returns 413 for bodies over the configured limit and stores nothing', function () {
    config(['relay.ingest.max_body_bytes' => 100]);
    [$source] = sourceWithDestinations();

    post($source->slug, str_repeat('x', 101))->assertStatus(413)->assertJsonPath('message', 'Payload too large (max 100 bytes).');
    post($source->slug, str_repeat('x', 100))->assertStatus(202);

    expect(WebhookEvent::count())->toBe(1);
});

it('rejects on a declared Content-Length before reading the body', function () {
    config(['relay.ingest.max_body_bytes' => 100]);
    [$source] = sourceWithDestinations();

    post($source->slug, '{}', ['CONTENT_LENGTH' => '5000000'])->assertStatus(413);
});

it('rate limits ingestion per source with 429 and Retry-After', function () {
    [$limited] = sourceWithDestinations(['rate_limit_rps' => 2]);
    [$other] = sourceWithDestinations();

    post($limited->slug, '{"n":1}')->assertStatus(202);
    post($limited->slug, '{"n":2}')->assertStatus(202);
    post($limited->slug, '{"n":3}')->assertStatus(429)->assertHeader('Retry-After', '1');

    // Other sources are unaffected.
    foreach (range(1, 5) as $i) {
        post($other->slug, "{\"m\":{$i}}")->assertStatus(202);
    }
    expect(WebhookEvent::where('source_id', $limited->id)->count())->toBe(2);
});

it('applies RELAY_INGEST_DEFAULT_RPS to sources without their own limit', function () {
    config(['relay.ingest.default_rps' => 1]);
    [$source] = sourceWithDestinations();
    [$override] = sourceWithDestinations(['rate_limit_rps' => 3]);

    post($source->slug, '{"n":1}')->assertStatus(202);
    post($source->slug, '{"n":2}')->assertStatus(429);
    foreach (range(1, 3) as $i) {
        post($override->slug, "{\"n\":{$i}}")->assertStatus(202);
    }
});

it('counts rejected signatures against the rate limit, so floods stay cheap', function () {
    [$source] = sourceWithDestinations(['provider' => 'github', 'signing_secret' => 's', 'rate_limit_rps' => 1]);

    post($source->slug, '{}', ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'])->assertStatus(401);
    post($source->slug, '{}', ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'])->assertStatus(429);
});

it('exposes and validates the per-source rate limit in the API', function () {
    $this->postJson('/api/sources', ['name' => 'S', 'slug' => 's1', 'rate_limit_rps' => 50], adminHeaders())
        ->assertCreated()->assertJsonPath('data.rate_limit_rps', 50);
    $this->postJson('/api/sources', ['name' => 'S', 'slug' => 's2', 'rate_limit_rps' => 0], adminHeaders())
        ->assertStatus(422)->assertJsonValidationErrors('rate_limit_rps');
});
