<?php

namespace App\Services\Security;

use RuntimeException;

class BlockedDestination extends RuntimeException
{
    /** @param  bool  $permanent  false for transient problems (e.g. DNS lookup failed) that may resolve on retry */
    public function __construct(string $message, public readonly bool $permanent = true)
    {
        parent::__construct($message);
    }
}
