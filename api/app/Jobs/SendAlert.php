<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class SendAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** @param  array<string, string|int|null>  $fields */
    public function __construct(public readonly string $title, public readonly array $fields = []) {}

    public function handle(): void
    {
        $lines = collect($this->fields)->map(fn ($v, $k) => "{$k}: {$v}")->values()->all();
        $dashboard = rtrim((string) config('relay.alerts.dashboard_url'), '/');
        if ($dashboard !== '') {
            $lines[] = "Dashboard: {$dashboard}/dead-letter";
        }

        if ($url = config('relay.alerts.slack_webhook_url')) {
            Http::timeout(10)->post($url, [
                'text' => "*{$this->title}*\n".implode("\n", $lines),
            ])->throw();
        }

        if ($to = config('relay.alerts.email')) {
            Mail::raw(implode("\n", $lines), function ($message) use ($to) {
                $message->to(array_map('trim', explode(',', $to)))->subject('[HookRelay] '.$this->title);
            });
        }
    }
}
