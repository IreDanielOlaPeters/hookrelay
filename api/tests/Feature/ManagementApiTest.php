<?php

use App\Models\Connection;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\Source;
use App\Services\Delivery\Deliverer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('rejects requests without the admin token', function () {
    $this->getJson('/api/sources')->assertStatus(401);
    $this->getJson('/api/sources', ['Authorization' => 'Bearer wrong'])->assertStatus(401);
    $this->getJson('/api/sources', adminHeaders())->assertOk();
});

it('creates, updates and deletes sources without leaking the secret', function () {
    $created = $this->postJson('/api/sources', [
        'name' => 'Stripe live', 'slug' => 'stripe-live', 'provider' => 'stripe', 'signing_secret' => 'whsec_123',
    ], adminHeaders())->assertCreated()
        ->assertJsonPath('data.has_signing_secret', true)
        ->assertJsonPath('data.ingest_url', 'http://localhost/in/stripe-live')
        ->assertJsonMissingPath('data.signing_secret');

    $id = $created->json('data.id');
    // Stored encrypted at rest.
    expect(DB::table('sources')->where('id', $id)->value('signing_secret'))->not->toContain('whsec_123')
        ->and(Source::find($id)->signing_secret)->toBe('whsec_123');

    $this->putJson("/api/sources/{$id}", ['signing_secret' => null], adminHeaders())
        ->assertOk()->assertJsonPath('data.has_signing_secret', false);
    $this->deleteJson("/api/sources/{$id}", [], adminHeaders())->assertNoContent();
    expect(Source::count())->toBe(0);
});

it('validates source input', function () {
    Source::create(['name' => 'x', 'slug' => 'taken']);

    $this->postJson('/api/sources', ['name' => '', 'slug' => 'taken', 'provider' => 'paypal'], adminHeaders())
        ->assertStatus(422)->assertJsonValidationErrors(['name', 'slug', 'provider']);
    $this->postJson('/api/sources', ['name' => 'ok', 'slug' => 'has spaces'], adminHeaders())
        ->assertStatus(422)->assertJsonValidationErrors(['slug']);
});

it('manages destinations and connections', function () {
    $source = Source::create(['name' => 's', 'slug' => 's']);
    $destination = $this->postJson('/api/destinations', [
        'name' => 'Hook', 'url' => 'https://example.test/hook', 'auth_header' => 'Bearer x', 'rate_limit_rps' => 5,
    ], adminHeaders())->assertCreated()
        ->assertJsonPath('data.has_auth_header', true)
        ->assertJsonPath('data.max_attempts', 6)
        ->assertJsonPath('data.timeout_ms', 10000)
        ->assertJsonPath('data.circuit.state', 'closed')
        ->json('data');

    $this->postJson('/api/destinations', ['name' => 'bad', 'url' => 'ftp://nope'], adminHeaders())->assertStatus(422);

    $connection = $this->postJson('/api/connections', ['source_id' => $source->id, 'destination_id' => $destination['id']], adminHeaders())
        ->assertCreated()->assertJsonPath('data.source.slug', 's')->json('data');
    $this->postJson('/api/connections', ['source_id' => $source->id, 'destination_id' => $destination['id']], adminHeaders())
        ->assertStatus(422)->assertJsonValidationErrors('destination_id');

    $this->putJson("/api/connections/{$connection['id']}", ['enabled' => false], adminHeaders())->assertOk()->assertJsonPath('data.enabled', false);
    $this->getJson("/api/connections?source_id={$source->id}", adminHeaders())->assertJsonCount(1, 'data');
    $this->deleteJson("/api/connections/{$connection['id']}", [], adminHeaders())->assertNoContent();
    expect(Connection::count())->toBe(0);

    $this->putJson("/api/destinations/{$destination['id']}", ['enabled' => false], adminHeaders())->assertJsonPath('data.enabled', false);
    $this->deleteJson("/api/destinations/{$destination['id']}", [], adminHeaders())->assertNoContent();
    expect(Destination::count())->toBe(0);
});

it('lists and shows events with deliveries and attempts', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    [$source] = sourceWithDestinations(destinations: 2);
    $id = $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CUSTOM' => 'yes'], '{"x":1}')->json('event_id');
    $delivery = Delivery::first();
    $delivery->update(['status' => 'delivering']);
    app(Deliverer::class)->deliver($delivery->id);

    $this->getJson("/api/events?source_id={$source->id}", adminHeaders())
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.delivery_counts.succeeded', 1)
        ->assertJsonPath('data.0.delivery_counts.pending', 1)
        ->assertJsonPath('data.0.source.slug', $source->slug);

    $this->getJson('/api/events?from=2000-01-01&to=2000-01-02', adminHeaders())->assertJsonPath('meta.total', 0);

    $this->getJson("/api/events/{$id}", adminHeaders())
        ->assertOk()
        ->assertJsonPath('data.body', '{"x":1}')
        ->assertJsonPath('data.headers.x-custom.0', 'yes')
        ->assertJsonCount(2, 'data.deliveries')
        ->assertJsonStructure(['data' => ['deliveries' => [['id', 'status', 'destination' => ['name'], 'attempts']]]]);

    $this->getJson('/api/events/not-a-uuid', adminHeaders())->assertNotFound();
    $this->getJson('/api/events/'.Str::uuid(), adminHeaders())->assertNotFound();
});

it('filters deliveries and reports stats', function () {
    Http::fake(['*' => Http::response('', 500)]);
    [$source, [$destination]] = sourceWithDestinations();
    $this->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"x":1}');
    $delivery = Delivery::sole();
    $delivery->update(['status' => 'delivering']);
    app(Deliverer::class)->deliver($delivery->id);

    $this->getJson("/api/deliveries?status=pending&destination_id={$destination->id}&has_error=1", adminHeaders())
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.last_error', 'HTTP 500')
        ->assertJsonPath('data.0.max_attempts', 6);
    $this->getJson('/api/deliveries?status=dead', adminHeaders())->assertJsonPath('meta.total', 0);
    $this->getJson('/api/deliveries?status=bogus', adminHeaders())->assertStatus(422);

    $this->getJson('/api/stats', adminHeaders())
        ->assertOk()
        ->assertJsonPath('window_hours', 24)
        ->assertJsonPath('events_received', 1)
        ->assertJsonPath('deliveries.pending', 1)
        ->assertJsonPath('deliveries.total', 1)
        ->assertJsonStructure(['latency_ms' => ['attempt' => ['p50', 'p95'], 'end_to_end' => ['p50', 'p95']]]);
});
