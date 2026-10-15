<?php

use App\Models\Attempt;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\ReplayBatch;
use App\Models\Source;
use App\Models\UserSession;
use App\Models\WebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Insert an event with deliveries in the given statuses, received $daysAgo days ago. */
function eventAged(Source $source, int $daysAgo, array $statuses = ['succeeded']): string
{
    $id = (string) Str::orderedUuid();
    $at = Carbon::now()->subDays($daysAgo);
    WebhookEvent::insert(['id' => $id, 'source_id' => $source->id, 'idempotency_key' => "k-{$id}", 'headers' => '{}', 'body' => '{}', 'received_at' => $at]);
    $destination = Destination::firstOrCreate(['name' => 'prune'], ['url' => 'https://x.test']);
    foreach ($statuses as $status) {
        $deliveryId = (string) Str::orderedUuid();
        Delivery::insert(['id' => $deliveryId, 'event_id' => $id, 'destination_id' => $destination->id, 'status' => $status, 'attempt_count' => 1, 'created_at' => $at, 'updated_at' => $at]);
        Attempt::insert(['delivery_id' => $deliveryId, 'started_at' => $at, 'duration_ms' => 5]);
    }

    return $id;
}

beforeEach(fn () => $this->source = Source::create(['name' => 's', 'slug' => 's', 'dedupe_window_seconds' => 86400]));

it('deletes delivered events older than the retention period, with their deliveries and attempts', function () {
    $old = eventAged($this->source, 8);
    $recent = eventAged($this->source, 2);
    $noDeliveries = eventAged($this->source, 30, []);

    $this->artisan('relay:prune')->expectsOutputToContain('Deleted 2 events')->assertSuccessful();

    expect(WebhookEvent::find($old))->toBeNull()
        ->and(WebhookEvent::find($noDeliveries))->toBeNull()
        ->and(WebhookEvent::find($recent))->not->toBeNull()
        ->and(Delivery::count())->toBe(1)
        ->and(Attempt::count())->toBe(1);
});

it('never deletes events with undelivered deliveries', function (string $status) {
    $keep = eventAged($this->source, 60, ['succeeded', $status]);

    $this->artisan('relay:prune')->assertSuccessful();

    expect(WebhookEvent::find($keep))->not->toBeNull();
})->with(['pending', 'delivering', 'failed', 'dead']);

it('keeps events inside their source dedupe window even past retention', function () {
    $longDedupe = Source::create(['name' => 'l', 'slug' => 'l', 'dedupe_window_seconds' => 30 * 86400]);
    $protected = eventAged($longDedupe, 10);
    $expired = eventAged($longDedupe, 31);

    $this->artisan('relay:prune', ['--days' => 7])->assertSuccessful();

    expect(WebhookEvent::find($protected))->not->toBeNull()->and(WebhookEvent::find($expired))->toBeNull();
});

it('deletes in batches until done', function () {
    foreach (range(1, 25) as $_) {
        eventAged($this->source, 10);
    }

    $this->artisan('relay:prune', ['--batch' => 10])->expectsOutputToContain('Deleted 25 events')->assertSuccessful();
    expect(WebhookEvent::count())->toBe(0);
});

it('honours --days and RELAY_RETENTION_DAYS, and --dry-run deletes nothing', function () {
    eventAged($this->source, 4);
    config(['relay.retention_days' => 3]);

    $this->artisan('relay:prune', ['--dry-run' => true])->expectsOutputToContain('Would delete 1 events')->assertSuccessful();
    expect(WebhookEvent::count())->toBe(1);

    $this->artisan('relay:prune', ['--days' => 5])->assertSuccessful();
    expect(WebhookEvent::count())->toBe(1);

    $this->artisan('relay:prune')->assertSuccessful();
    expect(WebhookEvent::count())->toBe(0);
});

it('also removes expired sessions and old finished replay batches', function () {
    [$session] = UserSession::start(adminUser());
    $session->forceFill(['expires_at' => now()->subDay()])->save();
    $oldBatch = ReplayBatch::create(['filters' => [], 'status' => 'completed']);
    ReplayBatch::whereKey($oldBatch->id)->update(['created_at' => now()->subDays(30)]);
    $running = ReplayBatch::create(['filters' => [], 'status' => 'running']);
    ReplayBatch::whereKey($running->id)->update(['created_at' => now()->subDays(30)]);

    $this->artisan('relay:prune')->assertSuccessful();

    expect(UserSession::count())->toBe(0)
        ->and(ReplayBatch::find($oldBatch->id))->toBeNull()
        ->and(ReplayBatch::find($running->id))->not->toBeNull();
});

it('is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'relay:prune'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('30 2 * * *');
});

it('refuses a retention under one day', function () {
    $this->artisan('relay:prune', ['--days' => 0])->assertFailed();
});
