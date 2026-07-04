<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordResource extends JsonResource
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
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
            'status' => $this->status->value,
            'visited_at' => $this->visited_at->toIso8601String(),
            'ai_summary' => $this->ai_summary,
            'blocks' => $this->whenLoaded('blocks', fn() => RecordBlockResource::collection($this->blocks)),
            'photos' => $this->whenLoaded('photos', fn() => PhotoResource::collection($this->photos)),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
