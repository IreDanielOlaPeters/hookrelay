<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    /** Waiting for its next_attempt_at (first try or a scheduled retry). */
    case Pending = 'pending';
    /** Claimed by the dispatcher and queued / in flight on a worker. */
    case Delivering = 'delivering';
    case Succeeded = 'succeeded';
    /** Terminal failure without retries. Reserved: Phase 1 retries every failure until `dead`. */
    case Failed = 'failed';
    /** Exhausted max_attempts. Lives in the dead-letter list until replayed. */
    case Dead = 'dead';
}
