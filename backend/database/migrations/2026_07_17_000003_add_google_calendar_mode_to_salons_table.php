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
        Schema::table('salons', function (Blueprint $table) {
            $table->string('google_calendar_mode')->nullable()->comment('per_staff/shared。null = 未設定（同期を使わない）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn('google_calendar_mode');
        });
    }
};
