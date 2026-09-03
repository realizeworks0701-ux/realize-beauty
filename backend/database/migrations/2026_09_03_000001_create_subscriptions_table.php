<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * サロンの契約状態（ADR-029）。Stripe Subscription の写しを1サロン1行で保持する。
     *
     * Stripe を決済・請求の Source of Truth とし、機能制御に必要な項目だけを同期する。
     * Soft Delete は採用しない。解約後も再契約に備えて行は残し、status で表現する。
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('plan')->comment('lite/standard/pro。Stripe の price から導出して保存する');
            $table->string('status')->comment('trialing/active/past_due/canceled/unpaid/incomplete/incomplete_expired/paused');
            $table->string('stripe_customer_id')->nullable()->unique()->comment('1サロン1 Customer。存在すれば再利用する');
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_price_id')->nullable()->comment('同期時点の Price。plan の導出根拠として保持する');
            $table->timestampTz('current_period_start')->nullable();
            $table->timestampTz('current_period_end')->nullable()->comment('解約申請中はこの時刻まで利用可能');
            $table->boolean('cancel_at_period_end')->default(false)->comment('解約申請済み。期間終了で canceled へ遷移する');
            $table->timestampTz('canceled_at')->nullable()->comment('解約が確定した日時');
            $table->timestampTz('ended_at')->nullable()->comment('契約が終了し利用停止となった日時');
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
