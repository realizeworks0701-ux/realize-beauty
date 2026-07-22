<?php

namespace App\Repositories;

use App\Models\GoogleBusyBlock;
use App\Models\GoogleCalendarConnection;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class GoogleBusyBlockRepository
{
    /**
     * 受信同期の upsert（キーは (google_calendar_connection_id, google_event_id)）。
     * salon_id / user_id は接続レコードから引き継ぐ（shared 接続は user_id = null）。
     */
    public function upsertByEventId(
        GoogleCalendarConnection $connection,
        string $googleEventId,
        Carbon $startAt,
        Carbon $endAt,
    ): GoogleBusyBlock {
        return GoogleBusyBlock::updateOrCreate(
            [
                'google_calendar_connection_id' => $connection->id,
                'google_event_id' => $googleEventId,
            ],
            [
                'salon_id' => $connection->salon_id,
                'user_id' => $connection->user_id,
                'start_at' => $startAt->copy()->utc(),
                'end_at' => $endAt->copy()->utc(),
            ],
        );
    }

    /**
     * 当該接続の busy ブロックを全削除する（対象カレンダー変更時の再構築前の掃除）。
     */
    public function deleteForConnection(int $connectionId): int
    {
        return GoogleBusyBlock::where('google_calendar_connection_id', $connectionId)->delete();
    }

    /**
     * @param  array<int, string>  $googleEventIds
     */
    public function deleteByEventIds(int $connectionId, array $googleEventIds): int
    {
        if ($googleEventIds === []) {
            return 0;
        }

        return GoogleBusyBlock::where('google_calendar_connection_id', $connectionId)
            ->whereIn('google_event_id', $googleEventIds)
            ->delete();
    }

    /**
     * 同期窓 [from, to) と重ならなくなった busy を削除する。
     */
    public function deleteOutsideWindow(int $connectionId, Carbon $from, Carbon $to): int
    {
        $fromUtc = $from->copy()->utc();
        $toUtc = $to->copy()->utc();

        return GoogleBusyBlock::where('google_calendar_connection_id', $connectionId)
            ->where(fn ($query) => $query
                ->where('end_at', '<=', $fromUtc)
                ->orWhere('start_at', '>=', $toUtc))
            ->delete();
    }

    /**
     * 全同期の照合削除（差集合の刈り取り）用。同期窓 [from, to) に重なる busy の event_id 一覧。
     *
     * @return array<int, string>
     */
    public function listEventIdsBetween(int $connectionId, Carbon $from, Carbon $to): array
    {
        return GoogleBusyBlock::where('google_calendar_connection_id', $connectionId)
            ->where('start_at', '<', $to->copy()->utc())
            ->where('end_at', '>', $from->copy()->utc())
            ->pluck('google_event_id')
            ->all();
    }

    /**
     * 予約カレンダーの「外部予定」表示用。期間 [from, to) に重なる busy をサロン単位で返す。
     *
     * @return Collection<int, GoogleBusyBlock>
     */
    public function listBySalonBetween(int $salonId, Carbon $from, Carbon $to): Collection
    {
        return GoogleBusyBlock::where('salon_id', $salonId)
            ->where('start_at', '<', $to->copy()->utc())
            ->where('end_at', '>', $from->copy()->utc())
            ->orderBy('start_at')
            ->get();
    }

    /**
     * 空き枠計算・公開予約の枠検証用。時間帯 [startAt, endAt) に重なる busy を返す。
     *
     * shared 接続の busy は user_id = null で保存され「サロン全体（＝全スタッフ）」を塞ぐため、
     * 当該スタッフの行に加えて user_id IS NULL の行も対象に含める。
     *
     * @return Collection<int, GoogleBusyBlock>
     */
    public function listOverlapping(int $salonId, int $userId, Carbon $startAt, Carbon $endAt): Collection
    {
        return GoogleBusyBlock::where('salon_id', $salonId)
            ->where(fn ($query) => $query
                ->where('user_id', $userId)
                ->orWhereNull('user_id'))
            ->where('start_at', '<', $endAt->copy()->utc())
            ->where('end_at', '>', $startAt->copy()->utc())
            ->get();
    }
}
