<?php

namespace App\Enums;

enum RecordStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
}
