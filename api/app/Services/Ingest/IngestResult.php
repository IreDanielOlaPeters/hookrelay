<?php

namespace App\Services\Ingest;

final class IngestResult
{
    public function __construct(
        public readonly string $eventId,
        public readonly bool $duplicate,
        public readonly int $deliveries = 0,
    ) {}
}
