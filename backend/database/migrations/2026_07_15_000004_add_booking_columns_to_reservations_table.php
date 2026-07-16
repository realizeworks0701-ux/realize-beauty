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
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('source')->default('staff')->comment('staff/web');
            $table->string('booking_token')->nullable()->unique()->comment('Web予約のキャンセルページURL用');
            $table->timestampTz('reminder_sent_at')->nullable()->comment('前日リマインダー送信日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['source', 'booking_token', 'reminder_sent_at']);
        });
    }
};
