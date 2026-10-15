<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSourceRequest;
use App\Http\Resources\SourceResource;
use App\Models\Source;
use App\Services\Ingest\Ingestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderBag;

class SourceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SourceResource::collection(Source::query()->orderBy('name')->get());
    }

    public function store(StoreSourceRequest $request): SourceResource
    {
        return new SourceResource(Source::create($request->validated()));
    }

    public function show(Source $source): SourceResource
    {
        return new SourceResource($source);
    }

    /**
     * Send a test webhook through the normal ingestion path (outbox, dispatcher, workers).
     * Signature checks are skipped because the caller is authenticated; the event is marked
     * unverified and carries an X-HookRelay-Test header.
     */
    public function test(Request $request, Source $source, Ingestor $ingestor): JsonResponse
    {
        $data = $request->validate(['body' => ['sometimes', 'nullable', 'string', 'max:65536']]);
        $body = $data['body'] ?? json_encode([
            'type' => 'hookrelay.test',
            'source' => $source->slug,
            'sent_at' => now()->toIso8601String(),
        ]);
        $testId = (string) Str::uuid();
        $contentType = json_validate($body) ? 'application/json' : 'text/plain';
        $headers = new HeaderBag([
            'Content-Type' => $contentType,
            'User-Agent' => 'HookRelay test sender',
            'X-HookRelay-Test' => $testId,
        ]);

        $result = $ingestor->ingest($source, $headers, $body, $contentType, trusted: true, idempotencyKey: "test:{$testId}");

        return response()->json(['event_id' => $result->eventId, 'deliveries' => $result->deliveries], 202);
    }

    public function update(StoreSourceRequest $request, Source $source): SourceResource
    {
        $source->update($request->validated());

        return new SourceResource($source);
    }

    public function destroy(Source $source): Response
    {
        $source->delete();

        return response()->noContent();
    }
}
