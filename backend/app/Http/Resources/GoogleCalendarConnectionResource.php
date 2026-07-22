<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Googleカレンダー接続（設定画面の表示用）。
 * トークン・同期内部状態（access_token / refresh_token / sync_token / channel_token）は一切返さない。
 */
class GoogleCalendarConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // per_staff は接続したスタッフ、shared は null
            'user' => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
            'google_account_email' => $this->google_account_email,
            'calendar_id' => $this->calendar_id,
            'status' => $this->status->value,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
