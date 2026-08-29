<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * price 導入前の既存予約に現在のメニュー価格を埋め戻す。
     * 予約時点の価格は復元できないため現在価格を近似値として使う（ADR-026）。
     * メニューは論理削除でも menus 行が残るため JOIN できる。
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE reservations r
               SET price = m.price
              FROM menus m
             WHERE r.menu_id = m.id
               AND r.price IS NULL
        SQL);
    }

    /**
     * 埋め戻した値と作成時スナップショットは区別できないため巻き戻しでは何もしない。
     */
    public function down(): void {}
};
