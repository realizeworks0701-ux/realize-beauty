<?php

namespace App\Services\Google;

use App\Models\GoogleCalendarConnection;
use App\Models\Reservation;

/**
 * RB の予約から Google イベントのペイロードを組み立てる（送信同期の insert/update と受信同期の巻き戻しで共用）。
 * shared モードは題名に担当スタッフ名を含める。エコー防止マーカーを必ず付与する（ADR-025 §4）。
 */
class GoogleEventPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(GoogleCalendarConnection $connection, Reservation $reservation): array
    {
        return [
            'summary' => $this->summary($connection, $reservation),
            'start' => ['dateTime' => $reservation->start_at->copy()->utc()->toRfc3339String(), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $reservation->end_at->copy()->utc()->toRfc3339String(), 'timeZone' => 'UTC'],
            'extendedProperties' => [
                'private' => [
                    'rb_reservation_id' => (string) $reservation->id,
                    'rb_salon_id' => (string) $reservation->salon_id,
                ],
            ],
        ];
    }

    private function summary(GoogleCalendarConnection $connection, Reservation $reservation): string
    {
        $menuName = $reservation->menu?->name ?? 'RB予約';

        // shared は1本のカレンダーに全スタッフ分を書くため担当スタッフ名を題名に含める
        if ($connection->user_id === null) {
            return $menuName.'（'.($reservation->user?->name ?? '担当未定').'）';
        }

        return $menuName;
    }
}
