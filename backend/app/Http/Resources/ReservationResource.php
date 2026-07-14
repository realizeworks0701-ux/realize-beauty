<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'kana' => $this->customer->kana,
                'phone' => $this->customer->phone,
            ],
            'menu' => [
                'id' => $this->menu->id,
                'name' => $this->menu->name,
                'price' => $this->menu->price,
                'duration_minutes' => $this->menu->duration_minutes,
                'is_active' => $this->menu->is_active,
            ],
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
            'start_at' => $this->start_at->toIso8601String(),
            'end_at' => $this->end_at->toIso8601String(),
            'status' => $this->status->value,
            'note' => $this->note,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
