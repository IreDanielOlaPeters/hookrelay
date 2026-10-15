<?php

use App\Enums\DeliveryStatus;
use App\Jobs\SendAlert;
use App\Models\Delivery;
use App\Services\Alerts\Alerter;
use App\Services\Delivery\Deliverer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function failingDelivery(array $attrs = []): Delivery
{
    [$source] = sourceWithDestinations(destinationAttributes: $attrs + ['name' => 'Billing']);
    $eventId = test()->call('POST', "/in/{$source->slug}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":'.random_int(1, 1e9).'}')->json('event_id');

    return Delivery::where('event_id', $eventId)->sole();
}

function attempt(Delivery $delivery): void
{
    $delivery->refresh()->update(['status' => DeliveryStatus::Delivering]);
    app(Deliverer::class)->deliver($delivery->id);
}

beforeEach(fn () => config(['relay.alerts.slack_webhook_url' => 'https://hooks.slack.test/services/T/B/X']));

it('alerts once when a delivery goes dead, and suppresses repeats within the cooldown', function () {
    Queue::fake();
    Http::fake(['*' => Http::response('', 500)]);
    $first = failingDelivery(['max_attempts' => 1]);
    attempt($first);

    Queue::assertPushedOn('default', SendAlert::class, fn ($job) => $job->title === 'Delivery to Billing is dead'
        && $job->fields['Delivery'] === $first->id && $job->fields['Last error'] === 'HTTP 500');

    // Another dead delivery to the same destination within the cooldown: no second alert.
    $second = Delivery::create(['event_id' => $first->event_id, 'destination_id' => $first->destination_id, 'status' => 'pending', 'next_attempt_at' => now()]);
    attempt($second);
    Queue::assertPushed(SendAlert::class, 1);

    // After the cooldown it alerts again.
    $this->travel(config('relay.alerts.cooldown_seconds') + 1)->seconds();
    app('redis')->connection()->flushdb();
    $third = Delivery::create(['event_id' => $first->event_id, 'destination_id' => $first->destination_id, 'status' => 'pending', 'next_attempt_at' => now()]);
    attempt($third);
    Queue::assertPushed(SendAlert::class, 2);
});

it('alerts when a circuit breaker opens', function () {
    Queue::fake();
    Http::fake(['*' => Http::response('', 503)]);
    $delivery = failingDelivery(['max_attempts' => 20]);
    foreach (range(1, 5) as $_) {
        attempt($delivery);
    }

    Queue::assertPushed(SendAlert::class, fn ($job) => $job->title === 'Circuit breaker opened for Billing');
    Queue::assertPushed(SendAlert::class, 1);
});

it('sends Slack and email notifications', function () {
    Http::fake(['hooks.slack.test/*' => Http::response('ok')]);
    config(['relay.alerts.email' => 'ops@example.test, oncall@example.test', 'mail.default' => 'array']);

    (new SendAlert('Delivery to Billing is dead', ['Destination' => 'Billing', 'Last error' => 'HTTP 500']))->handle();

    Http::assertSent(fn ($r) => $r->url() === 'https://hooks.slack.test/services/T/B/X'
        && str_contains($r['text'], '*Delivery to Billing is dead*')
        && str_contains($r['text'], 'Last error: HTTP 500'));
    $sent = app('mailer')->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1);
    $email = $sent[0]->getOriginalMessage();
    expect($email->getSubject())->toBe('[HookRelay] Delivery to Billing is dead')
        ->and(array_map(fn ($a) => $a->getAddress(), $email->getTo()))->toBe(['ops@example.test', 'oncall@example.test'])
        ->and($email->getTextBody())->toContain('Destination: Billing');
});

it('does nothing when no alert channel is configured', function () {
    Queue::fake();
    config(['relay.alerts.slack_webhook_url' => null, 'relay.alerts.email' => null]);

    expect(app(Alerter::class)->notify('delivery_dead', 'x', 'title'))->toBeFalse();
    Queue::assertNothingPushed();
});

it('counts sent and suppressed alerts in metrics', function () {
    Queue::fake();
    app(Alerter::class)->notify('circuit_open', 'd1', 't');
    app(Alerter::class)->notify('circuit_open', 'd1', 't');

    expect($this->get('/metrics')->getContent())
        ->toContain('hookrelay_alerts_total{kind="circuit_open",result="sent"} 1')
        ->toContain('hookrelay_alerts_total{kind="circuit_open",result="suppressed"} 1');
});
