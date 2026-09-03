<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stripe Webhook の受信記録（ADR-029）。冪等性の担保が唯一の目的。
     *
     * Stripe は同一イベントを複数回送信しうるため、stripe_event_id の unique 制約を
     * 二重処理のガードに使う。カード情報・請求先などの個人情報は保存しない
     * （payload をそのまま残さない。ADR-028 のログ方針に合わせる）。
     */
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique()->comment('冪等キー。evt_xxx');
            $table->string('type')->comment('Stripe のイベント種別');
            $table->string('status')->comment('processing/processed/skipped/failed');
            $table->text('message')->nullable()->comment('skipped/failed の理由。個人情報を含めない');
            $table->timestampTz('occurred_at')->nullable()->comment('Stripe 側のイベント発生時刻');
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
