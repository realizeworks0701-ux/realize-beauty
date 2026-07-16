<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Web予約ページ情報（Salon モデルを受け取る）。
 */
class BookingPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'booking_slug' => $this->booking_slug,
            'booking_page_url' => rtrim(config('app.url'), '/').'/booking/'.$this->booking_slug,
        ];
    }
}
