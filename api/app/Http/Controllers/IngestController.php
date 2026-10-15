<?php

namespace App\Http\Controllers;

use App\Models\Source;
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
    public function __invoke(Request $request, string $slug, Ingestor $ingestor): JsonResponse
    {
        // TODO(multi-tenancy): resolve the source per tenant (e.g. /in/{tenant}/{slug} or by host).
        $source = Source::query()->where('slug', $slug)->first();
        if (! $source) {
            return response()->json(['message' => 'Unknown source.'], 404);
        }

        try {
            $result = $ingestor->ingest(
                $source,
                $request->headers,
                $request->getContent(),
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
}
