<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kana' => $this->kana,
            'gender' => $this->gender,
            'birthday' => $this->birthday?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'memo' => $this->memo,
            'first_visit_at' => $this->first_visit_at?->toDateString(),
            'last_visit_at' => $this->last_visit_at?->toDateString(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
