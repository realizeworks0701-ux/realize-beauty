<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Web予約の確定内容（Reservation モデルを受け取る）。
 * line は連携案内がある場合のみ値を持つ（LINE連携が無効・顧客が連携済みなら null）。
 */
class PublicReservationResource extends JsonResource
{
    /**
     * @param  array{add_friend_url: string, link_code: string}|null  $line
     */
    public function __construct($resource, private readonly ?array $line = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'booking_token' => $this->booking_token,
            'start_at' => $this->start_at->toIso8601String(),
            'end_at' => $this->end_at->toIso8601String(),
            'menu_name' => $this->menu->name,
            'staff_name' => $this->user->name,
            'line' => $this->line,
        ];
    }
}
