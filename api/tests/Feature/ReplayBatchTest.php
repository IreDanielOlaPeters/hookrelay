<?php

use App\Jobs\RunReplayBatch;
use App\Models\Delivery;
use App\Models\ReplayBatch;
use App\Services\Delivery\Replayer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function deadRows(int $count): void
{
    [$source, [$destination]] = sourceWithDestinations();
    test()->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"seed":1}');
    $template = Delivery::sole();
    $template->update(['status' => 'dead']);
    $rows = [];
    foreach (range(2, $count) as $_) {
        $rows[] = ['id' => (string) Str::orderedUuid(), 'event_id' => $template->event_id, 'destination_id' => $destination->id,
            'status' => 'dead', 'attempt_count' => 6, 'created_at' => now(), 'updated_at' => now()];
    }
    Delivery::insert($rows);
}

it('queues bulk replay as a batch on the default queue and returns 202 immediately', function () {
    Queue::fake();
    deadRows(3);

    $response = $this->postJson('/api/deliveries/replay', ['status' => 'dead'], adminHeaders())
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.filters.status', 'dead');

    Queue::assertPushedOn('default', RunReplayBatch::class, fn ($job) => $job->batchId === $response->json('data.id'));
    expect(Delivery::where('status', 'dead')->count())->toBe(3); // nothing replayed yet
});

it('runs the batch, recording total, progress and completion', function () {
    Queue::fake();
    deadRows(1203);
    $batchId = $this->postJson('/api/deliveries/replay', [], adminHeaders())->json('data.id');

    (new RunReplayBatch($batchId))->handle(app(Replayer::class));

    $batch = ReplayBatch::find($batchId);
    expect($batch->status)->toBe('completed')
        ->and($batch->total)->toBe(1203)
        ->and($batch->processed)->toBe(1203)
        ->and($batch->replayed)->toBe(1203)
        ->and($batch->started_at)->not->toBeNull()
        ->and($batch->finished_at)->not->toBeNull()
        ->and(Delivery::where('status', 'pending')->count())->toBe(1203);

    $this->getJson("/api/replay-batches/{$batchId}", adminHeaders())
        ->assertOk()->assertJsonPath('data.progress', 1)->assertJsonPath('data.replayed', 1203);
    $this->getJson('/api/replay-batches', adminHeaders())->assertOk()->assertJsonPath('data.0.id', $batchId);
});

it('reports progress after every chunk', function () {
    deadRows(1201);
    $seen = [];

    app(Replayer::class)->replayMatching(['status' => 'dead'], function (int $processed) use (&$seen) {
        $seen[] = $processed;
    });

    expect($seen)->toBe([500, 1000, 1201]);
});

it('marks the batch failed when the job fails', function () {
    $batch = ReplayBatch::create(['filters' => ['status' => 'dead']]);

    (new RunReplayBatch($batch->id))->failed(new RuntimeException('database went away'));

    expect($batch->refresh()->status)->toBe('failed')->and($batch->error)->toBe('database went away');
});

it('ignores a batch that is not queued (e.g. a duplicate job)', function () {
    deadRows(2);
    $batch = ReplayBatch::create(['filters' => ['status' => 'dead'], 'status' => 'completed']);

    (new RunReplayBatch($batch->id))->handle(app(Replayer::class));

    expect(Delivery::where('status', 'dead')->count())->toBe(2);
});
