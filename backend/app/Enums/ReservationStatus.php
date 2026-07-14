<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Reserved = 'reserved';
    case Visited = 'visited';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
}
