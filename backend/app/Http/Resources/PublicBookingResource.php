<?php

namespace App\Http\Resources;

use App\Enums\ReservationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * キャンセルページ用の予約概要（Reservation モデルを受け取る）。
 * 認証なしで返すため顧客氏名等の個人情報は含めない。
 */
class PublicBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'salon_name' => $this->salon->name,
            'menu_name' => $this->menu->name,
            'staff_name' => $this->user->name,
            'start_at' => $this->start_at->toIso8601String(),
            'end_at' => $this->end_at->toIso8601String(),
            'status' => $this->status->value,
            'can_cancel' => $this->status === ReservationStatus::Reserved
                && now()->lt($this->start_at),
        ];
    }
}
