<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessHourResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'day_of_week' => $this->day_of_week,
            'is_closed' => $this->is_closed,
            'open_time' => substr($this->open_time, 0, 5),
            'close_time' => substr($this->close_time, 0, 5),
        ];
    }
}
