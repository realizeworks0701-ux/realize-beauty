<?php

namespace App\Enums;

enum GoogleCalendarConnectionStatus: string
{
    case Active = 'active';
    case NeedsReconnect = 'needs_reconnect';
}
