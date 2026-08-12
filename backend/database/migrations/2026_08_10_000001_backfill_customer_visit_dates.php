<?php

use App\Enums\ReservationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * first_visit_at / last_visit_at は導入以来アプリから書き込まれておらず、
     * 既存顧客の値が空のままになっている。ReservationService の再計算と同じ定義
     * （status=visited・未削除の start_at をサロンTZの日付に変換した MIN/MAX）で埋め戻す。
     * visited 予約を持つ顧客のみ更新するため、既存の値を null で潰すことはない。
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE customers c
               SET first_visit_at = v.first_visit_at,
                   last_visit_at  = v.last_visit_at
              FROM (SELECT customer_id,
                           MIN((start_at AT TIME ZONE ?)::date) AS first_visit_at,
                           MAX((start_at AT TIME ZONE ?)::date) AS last_visit_at
                      FROM reservations
                     WHERE status = ? AND deleted_at IS NULL
                     GROUP BY customer_id) v
             WHERE c.id = v.customer_id
        SQL, [
            config('app.salon_timezone'),
            config('app.salon_timezone'),
            ReservationStatus::Visited->value,
        ]);
    }

    /**
     * 埋め戻したデータは以後アプリが維持するため、巻き戻しでは何もしない
     * （null に戻すと正当な来店日まで失われる）。
     */
    public function down(): void {}
};
