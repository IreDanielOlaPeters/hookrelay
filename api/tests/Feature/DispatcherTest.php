<?php

use App\Enums\DeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\Delivery;
use App\Services\Delivery\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function ingest(string $slug, string $body): void
{
    test()->call('POST', "/in/{$slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(202);
}

it('claims only due pending deliveries, marks them delivering and queues one job each', function () {
    Queue::fake();
    [$source] = sourceWithDestinations(destinations: 2);
    ingest($source->slug, '{"n":1}');
    ingest($source->slug, '{"n":2}');
    // One delivery not due yet.
    Delivery::query()->limit(1)->update(['next_attempt_at' => Carbon::now()->addHour()]);

    $dispatched = app(Dispatcher::class)->dispatchDue(100);

    expect($dispatched)->toBe(3)
        ->and(Delivery::where('status', DeliveryStatus::Delivering)->count())->toBe(3)
        ->and(Delivery::where('status', DeliveryStatus::Pending)->count())->toBe(1);
    Queue::assertPushedOn('deliveries', DeliverWebhook::class);
    Queue::assertPushed(DeliverWebhook::class, 3);

    // A second pass finds nothing new.
    expect(app(Dispatcher::class)->dispatchDue(100))->toBe(0);
});

it('respects the batch limit', function () {
    Queue::fake();
    [$source] = sourceWithDestinations();
    foreach (range(1, 5) as $i) {
        ingest($source->slug, "{\"n\":{$i}}");
    }

    expect(app(Dispatcher::class)->dispatchDue(2))->toBe(2)
        ->and(app(Dispatcher::class)->dispatchDue(2))->toBe(2)
        ->and(app(Dispatcher::class)->dispatchDue(2))->toBe(1);
});

it('leaves deliveries for disabled destinations pending', function () {
    Queue::fake();
    [$source, [$destination]] = sourceWithDestinations();
    ingest($source->slug, '{"n":1}');
    $destination->update(['enabled' => false]);

    expect(app(Dispatcher::class)->dispatchDue(100))->toBe(0)
        ->and(Delivery::sole()->status)->toBe(DeliveryStatus::Pending);
});

it('recovers deliveries stuck in delivering for more than 5 minutes', function () {
    [$source] = sourceWithDestinations(destinations: 2);
    ingest($source->slug, '{"n":1}');
    [$stuck, $fresh] = Delivery::all();
    Delivery::whereKey($stuck->id)->update(['status' => 'delivering', 'updated_at' => Carbon::now()->subMinutes(6)]);
    Delivery::whereKey($fresh->id)->update(['status' => 'delivering', 'updated_at' => Carbon::now()->subMinutes(2)]);

    expect(app(Dispatcher::class)->recoverStuck(300))->toBe(1)
        ->and($stuck->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($fresh->refresh()->status)->toBe(DeliveryStatus::Delivering);
});

describe('concurrency', function () {
    // These tests need committed rows visible to other connections and processes,
    // so they write through separate connections outside the RefreshDatabase transaction.
    beforeEach(function () {
        foreach (['relay_a', 'relay_b'] as $name) {
            config(["database.connections.{$name}" => config('database.connections.pgsql')]);
        }
        $this->slug = 'concurrency-'.Str::lower(Str::random(8));
    });

    afterEach(function () {
        $db = DB::connection('relay_a');
        $db->table('jobs')->delete();
        $db->table('sources')->where('slug', $this->slug)->delete(); // cascades to events/deliveries
        $db->table('destinations')->where('name', $this->slug)->delete();
        DB::purge('relay_a');
        DB::purge('relay_b');
    });

    function seedCommittedDeliveries(string $slug, int $count): void
    {
        $db = DB::connection('relay_a');
        $now = Carbon::now()->subSecond();
        $sourceId = (string) Str::orderedUuid();
        $destinationId = (string) Str::orderedUuid();
        $db->table('sources')->insert(['id' => $sourceId, 'name' => $slug, 'slug' => $slug, 'provider' => 'generic', 'created_at' => $now, 'updated_at' => $now]);
        $db->table('destinations')->insert(['id' => $destinationId, 'name' => $slug, 'url' => 'https://x.test', 'created_at' => $now, 'updated_at' => $now]);

        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            $events = [];
            $deliveries = [];
            foreach ($chunk as $i) {
                $eventId = (string) Str::orderedUuid();
                $events[] = ['id' => $eventId, 'source_id' => $sourceId, 'idempotency_key' => "k{$i}", 'headers' => '{}', 'body' => '{}', 'received_at' => $now];
                $deliveries[] = ['id' => (string) Str::orderedUuid(), 'event_id' => $eventId, 'destination_id' => $destinationId, 'status' => 'pending', 'attempt_count' => 0, 'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            }
            $db->table('events')->insert($events);
            $db->table('deliveries')->insert($deliveries);
        }
    }

    it('never lets two dispatchers claim the same delivery (SKIP LOCKED)', function () {
        seedCommittedDeliveries($this->slug, 150);
        $a = new Dispatcher('relay_a');
        $b = new Dispatcher('relay_b');

        // A claims inside an open transaction, so its rows stay locked while B runs.
        DB::connection('relay_a')->beginTransaction();
        $claimedByA = $a->claim(100);
        $claimedByB = $b->claim(1000); // must skip A's locked rows instead of waiting or re-claiming them
        DB::connection('relay_a')->commit();

        expect($claimedByA)->toHaveCount(100)
            ->and($claimedByB)->toHaveCount(50)
            ->and(array_intersect($claimedByA, $claimedByB))->toBeEmpty()
            ->and($b->claim(1000))->toBeEmpty();
    });

    it('dispatches each delivery exactly once with two dispatcher processes running at the same time', function () {
        seedCommittedDeliveries($this->slug, 600);

        $env = [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'QUEUE_CONNECTION' => 'database',
            'RELAY_QUEUE' => 'deliveries',
        ];
        $processes = [];
        foreach (range(1, 2) as $_) {
            $process = new Process([PHP_BINARY, 'artisan', 'relay:dispatch', '--drain', '--limit=20'], base_path(), $env);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        }

        $dispatched = array_map('intval', array_map(
            fn ($p) => preg_replace('/\D/', '', $p->getOutput()),
            $processes,
        ));
        $ids = DB::connection('relay_a')->table('jobs')->pluck('payload')
            ->map(fn ($payload) => unserialize(json_decode($payload, true)['data']['command'])->deliveryId);

        expect(array_sum($dispatched))->toBe(600)
            ->and($ids)->toHaveCount(600)
            ->and($ids->unique())->toHaveCount(600)
            ->and(DB::connection('relay_a')->table('deliveries')->where('status', 'delivering')->count())->toBe(600);
    });
});
