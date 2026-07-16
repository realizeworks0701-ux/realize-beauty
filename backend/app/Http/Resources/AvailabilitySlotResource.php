<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 空き枠（salon_timezone の Carbon を受け取る）。
 */
class AvailabilitySlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'start_at' => $this->resource->toIso8601String(),
        ];
    }
}
