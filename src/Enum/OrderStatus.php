<?php

namespace App\Enum;

enum OrderStatus: string
{
    case Placed = 'placed';
    case Shipped = 'shipped';
}
