<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Services\Delivery\TokenBucket;
use App\Services\Ingest\Ingestor;
use App\Services\Ingest\InvalidSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /in/{slug}: verify, dedupe, write to the outbox, answer. Nothing else.
 * Delivery happens asynchronously so providers get a fast 202.
 */
class IngestController extends Controller
{
    public function __invoke(Request $request, string $slug, Ingestor $ingestor, TokenBucket $bucket): JsonResponse
    {
        // Cheapest check first: refuse oversized bodies before touching the database.
        $maxBytes = (int) config('relay.ingest.max_body_bytes');
        $declared = $request->headers->get('Content-Length');
        if (($declared !== null && (int) $declared > $maxBytes)) {
            return $this->tooLarge($maxBytes);
        }
        $body = $request->getContent();
        if (strlen($body) > $maxBytes) {
            return $this->tooLarge($maxBytes);
        }

        // TODO(multi-tenancy): resolve the source per tenant (e.g. /in/{tenant}/{slug} or by host).
        $source = Source::query()->where('slug', $slug)->first();
        if (! $source) {
            return response()->json(['message' => 'Unknown source.'], 404);
        }

        // Per-source rate limit, checked before signature verification so floods stay cheap.
        $rps = $source->rate_limit_rps ?? config('relay.ingest.default_rps');
        if ($rps && ! $bucket->takeFrom("relay:ingest:{$source->id}", (int) $rps)) {
            return response()->json(['message' => 'Rate limit exceeded for this source.'], 429, ['Retry-After' => '1']);
        }

        try {
            $result = $ingestor->ingest(
                $source,
                $request->headers,
                $body,
                $request->headers->get('Content-Type'),
            );
        } catch (InvalidSignature) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        if ($result->duplicate) {
            return response()->json(['event_id' => $result->eventId, 'duplicate' => true], 200);
        }

        return response()->json(['event_id' => $result->eventId, 'deliveries' => $result->deliveries], 202);
    }

    private function tooLarge(int $maxBytes): JsonResponse
    {
        return response()->json(['message' => "Payload too large (max {$maxBytes} bytes)."], 413);
    }
}
