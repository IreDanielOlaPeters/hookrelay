<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    /** Waiting for its next_attempt_at (first try or a scheduled retry). */
    case Pending = 'pending';
    /** Claimed by the dispatcher and queued / in flight on a worker. */
    case Delivering = 'delivering';
    case Succeeded = 'succeeded';
    /**
     * Terminal failure without retries: the destination answered with a permanent 4xx
     * (anything but 408/429) or the target is blocked by the SSRF guard. Replayable.
     */
    case Failed = 'failed';
    /** Exhausted max_attempts. Lives in the dead-letter list until replayed. */
    case Dead = 'dead';
}
