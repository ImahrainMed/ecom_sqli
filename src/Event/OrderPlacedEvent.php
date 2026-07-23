<?php

namespace App\Event;

use App\Entity\Order;
use Symfony\Contracts\EventDispatcher\Event;

final class OrderPlacedEvent extends Event
{
    public function __construct(
        private readonly Order $order,
    ) {
    }

    public function getOrder(): Order
    {
        return $this->order;
    }
}
