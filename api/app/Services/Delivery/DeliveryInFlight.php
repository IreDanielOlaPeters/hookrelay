<?php

namespace App\Services\Delivery;

use RuntimeException;

class DeliveryInFlight extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Delivery is currently being delivered; try again shortly.');
    }
}
