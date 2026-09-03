<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 契約状態の変化を追跡する業務監査ログ（ADR-029）。
     *
     * stripe_webhook_events が「Stripe から何を受け取ったか」を記録するのに対し、
     * こちらは「契約がどう変わったか」を記録する。契約開始・プラン変更・支払い失敗・
     * 解約申請・契約終了・利用停止を後から追跡できるようにする。
     */
    public function up(): void
    {
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('type')->comment('started/plan_changed/payment_failed/cancel_requested/cancel_revoked/suspended/ended/status_changed');
            $table->string('from_plan')->nullable();
            $table->string('to_plan')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('stripe_event_id')->nullable()->comment('Webhook 起点の場合の evt_xxx。手動操作起点なら null');
            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->index(['salon_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
