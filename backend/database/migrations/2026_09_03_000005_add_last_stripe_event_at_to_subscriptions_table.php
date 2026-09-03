<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 適用済み Stripe イベントの発生時刻（ADR-029）。
     *
     * Stripe は Webhook の順序を保証しない。遅れて届いた古い customer.subscription.updated が
     * 解約済みの契約を active に巻き戻すと、契約終了後もサービスを使えてしまう。
     * ここに最後に適用したイベントの時刻を持ち、これより古いイベントは無視する。
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestampTz('last_stripe_event_at')
                ->nullable()
                ->after('trial_ends_at')
                ->comment('最後に適用した Stripe イベントの発生時刻。これより古いイベントは無視する');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('last_stripe_event_at');
        });
    }
};
