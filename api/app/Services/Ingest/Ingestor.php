<?php

namespace App\Services\Ingest;

use App\Enums\DeliveryStatus;
use App\Models\Source;
use App\Models\WebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Transactional outbox: the event and one pending delivery per enabled connection
 * are written in a single transaction, so an accepted webhook is never lost and
 * never half-stored. Actual delivery happens later via the dispatcher.
 */
class Ingestor
{
    public function __construct(private readonly SignatureVerifier $verifier) {}

    /**
     * @throws InvalidSignature
     */
    public function ingest(Source $source, HeaderBag $headers, string $body, ?string $contentType): IngestResult
    {
        $verified = false;
        if ($source->signing_secret) {
            if (! $this->verifier->verify($source->provider, $headers, $body, $source->signing_secret)) {
                throw new InvalidSignature;
            }
            $verified = true;
        }

        $key = IdempotencyKey::for($source->provider, $headers, $body);
        // Postgres `text` rejects invalid UTF-8 and NUL bytes; keep such bodies byte-exact as base64.
        [$storedBody, $encoding] = mb_check_encoding($body, 'UTF-8') && ! str_contains($body, "\0")
            ? [$body, WebhookEvent::ENCODING_UTF8]
            : [base64_encode($body), WebhookEvent::ENCODING_BASE64];

        return DB::transaction(function () use ($source, $headers, $storedBody, $encoding, $contentType, $verified, $key) {
            $now = Carbon::now();
            $eventId = (string) Str::orderedUuid();
            $event = [
                'id' => $eventId,
                'source_id' => $source->id,
                'idempotency_key' => $key,
                'headers' => json_encode($headers->all(), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'body' => $storedBody,
                'body_encoding' => $encoding,
                'content_type' => $contentType,
                'verified' => $verified,
                'received_at' => $now,
            ];

            $inserted = DB::table('events')->insertOrIgnore($event);

            if ($inserted === 0) {
                $existing = DB::table('events')
                    ->where('source_id', $source->id)
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first(['id', 'received_at']);

                // Within the dedupe window: a true duplicate, store nothing.
                if (Carbon::parse($existing->received_at)->addSeconds($source->dedupe_window_seconds)->isFuture()) {
                    return new IngestResult($existing->id, duplicate: true);
                }

                // Outside the window: retire the old key so the same payload counts as a new event.
                DB::table('events')->where('id', $existing->id)
                    ->update(['idempotency_key' => Str::limit($key, 200, '').':expired:'.$existing->id]);

                DB::table('events')->insert($event);
            }

            $destinationIds = DB::table('connections')
                ->where('source_id', $source->id)
                ->where('enabled', true)
                ->pluck('destination_id');

            $rows = $destinationIds->map(fn ($destinationId) => [
                'id' => (string) Str::orderedUuid(),
                'event_id' => $eventId,
                'destination_id' => $destinationId,
                'status' => DeliveryStatus::Pending->value,
                'attempt_count' => 0,
                'next_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($rows !== []) {
                DB::table('deliveries')->insert($rows);
            }

            return new IngestResult($eventId, duplicate: false, deliveries: count($rows));
        });
    }
}
