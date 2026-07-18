<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('google_busy_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            // 接続の写しにすぎないため、接続の物理削除で一緒に消す（ERD の FK 方針の例外）
            $table->foreignId('google_calendar_connection_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete()->comment('null = サロン全体を塞ぐ（shared モード）');
            $table->string('google_event_id')->comment('取り込み元イベントID（upsert キー）');
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->timestamps();

            $table->unique(['google_calendar_connection_id', 'google_event_id']);
            $table->index(['salon_id', 'start_at']);
            $table->index(['user_id', 'start_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_busy_blocks');
    }
};
