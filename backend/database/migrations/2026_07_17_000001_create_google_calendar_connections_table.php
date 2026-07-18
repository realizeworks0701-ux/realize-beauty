<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('google_calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete()->comment('null = サロン共有接続（shared モード）');
            $table->string('google_account_email')->comment('calendarList の primary エントリの id（表示用）');
            $table->string('calendar_id')->default('primary')->comment('対象カレンダー。primary はエイリアス');
            $table->text('access_token')->comment('暗号化保存（encrypted cast）');
            $table->text('refresh_token')->comment('暗号化保存（encrypted cast）');
            $table->timestampTz('token_expires_at');
            $table->text('sync_token')->nullable()->comment('events.list の増分同期用 nextSyncToken。全ページ適用・コミット後に更新する');
            $table->string('channel_id')->nullable()->unique()->comment('webhook の X-Goog-Channel-ID 照合キー');
            $table->string('channel_resource_id')->nullable()->comment('channels.stop に必要な resourceId');
            $table->string('channel_token')->nullable()->comment('webhook 検証用の秘密値（X-Goog-Channel-Token と照合）');
            $table->timestampTz('channel_expires_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->string('status')->default('active')->comment('active/needs_reconnect');
            $table->timestamps();
        });

        // per_staff モード: 1スタッフ1接続
        DB::statement(
            'CREATE UNIQUE INDEX google_calendar_connections_salon_id_user_id_unique
             ON google_calendar_connections (salon_id, user_id) WHERE user_id IS NOT NULL',
        );
        // shared モード: 1サロン1接続
        DB::statement(
            'CREATE UNIQUE INDEX google_calendar_connections_salon_id_shared_unique
             ON google_calendar_connections (salon_id) WHERE user_id IS NULL',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS google_calendar_connections_salon_id_user_id_unique');
        DB::statement('DROP INDEX IF EXISTS google_calendar_connections_salon_id_shared_unique');

        Schema::dropIfExists('google_calendar_connections');
    }
};
