<?php

use App\Enums\DeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\Attempt;
use App\Models\Delivery;
use App\Services\Delivery\Deliverer;
use App\Services\Delivery\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** Callers fake HTTP so that the first $maxAttempts calls fail. */
function deadDelivery(int $maxAttempts = 2): Delivery
{
    [$source] = sourceWithDestinations(destinationAttributes: ['max_attempts' => $maxAttempts]);
    test()->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"r":'.random_int(1, 1e9).'}');
    $delivery = Delivery::latest('id')->first();

    foreach (range(1, $maxAttempts) as $_) {
        $delivery->refresh()->update(['status' => DeliveryStatus::Delivering]);
        app(Deliverer::class)->deliver($delivery->id);
    }

    return $delivery->refresh();
}

it('resets a dead delivery and it then succeeds, keeping the attempt history', function () {
    Http::fakeSequence()->push('', 500)->push('', 500)->push('ok', 200);
    $delivery = deadDelivery();
    expect($delivery->status)->toBe(DeliveryStatus::Dead);

    $this->postJson("/api/deliveries/{$delivery->id}/replay", [], adminHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.attempt_count', 0)
        ->assertJsonCount(2, 'data.attempts');

    $delivery->refresh();
    expect($delivery->next_attempt_at->lessThanOrEqualTo(Carbon::now()))->toBeTrue();

    // The dispatcher picks it up and the (now healthy) destination accepts it.
    Queue::fake();
    expect(app(Dispatcher::class)->dispatchDue(100))->toBe(1);
    Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->deliveryId === $delivery->id);
    app(Deliverer::class)->deliver($delivery->id);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Succeeded)
        ->and($delivery->attempt_count)->toBe(1)
        ->and(Attempt::where('delivery_id', $delivery->id)->count())->toBe(3);
});

it('refuses to replay a delivery that is in flight', function () {
    Http::fake(['*' => Http::response('', 500)]);
    $delivery = deadDelivery();
    $delivery->update(['status' => DeliveryStatus::Delivering]);

    $this->postJson("/api/deliveries/{$delivery->id}/replay", [], adminHeaders())->assertStatus(409);
});

it('bulk replays dead deliveries matching the filters, in chunks', function () {
    Http::fake(['*' => Http::response('', 500)]);
    $a = deadDelivery();
    $b = deadDelivery();
    $c = deadDelivery();
    Delivery::whereKey($c->id)->update(['created_at' => Carbon::parse('2020-01-01')]);

    // Filter by destination.
    $this->postJson('/api/deliveries/replay', ['status' => 'dead', 'destination_id' => $a->destination_id], adminHeaders())
        ->assertStatus(202)->assertJsonPath('data.replayed', 1);
    expect($a->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($b->refresh()->status)->toBe(DeliveryStatus::Dead);

    // Filter by date range (whole days).
    $this->postJson('/api/deliveries/replay', ['from' => '2019-12-31', 'to' => '2020-01-01'], adminHeaders())
        ->assertStatus(202)->assertJsonPath('data.replayed', 1);
    expect($c->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($b->refresh()->status)->toBe(DeliveryStatus::Dead);

    // No filters: everything still dead.
    $this->postJson('/api/deliveries/replay', [], adminHeaders())->assertStatus(202)->assertJsonPath('data.replayed', 1);
    expect(Delivery::where('status', 'dead')->count())->toBe(0);
});

it('bulk replays more deliveries than one chunk', function () {
    Http::fake(['*' => Http::response('', 500)]);
    deadDelivery(1);
    $template = Delivery::sole();
    $rows = [];
    foreach (range(1, 1200) as $_) {
        $rows[] = ['id' => (string) Str::orderedUuid(), 'event_id' => $template->event_id, 'destination_id' => $template->destination_id,
            'status' => 'dead', 'attempt_count' => 1, 'created_at' => now(), 'updated_at' => now()];
    }
    Delivery::insert($rows);

    $this->postJson('/api/deliveries/replay', ['status' => 'dead'], adminHeaders())
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.total', 1201)
        ->assertJsonPath('data.processed', 1201)
        ->assertJsonPath('data.replayed', 1201);
    expect(Delivery::where('status', 'pending')->count())->toBe(1201);
});
