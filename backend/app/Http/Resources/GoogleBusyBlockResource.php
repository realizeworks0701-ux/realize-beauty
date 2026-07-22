<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 外部予定（busy ブロック）。プライバシー配慮でタイトル等の内容は保存も返却もしない。
 * 返すのは id / start_at / end_at / user_id のみ（user_id が null は shared = サロン全体を塞ぐ）。
 */
class GoogleBusyBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'start_at' => $this->start_at->toIso8601String(),
            'end_at' => $this->end_at->toIso8601String(),
            'user_id' => $this->user_id,
        ];
    }
}
