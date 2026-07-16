<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->string('booking_slug')->nullable()->unique()->comment('公開Web予約ページURL用（英数小文字16文字）');
        });

        // 既存サロンへのバックフィル（新規サロンは Salon モデルの creating フックで生成）
        $ids = DB::table('salons')->whereNull('booking_slug')->pluck('id');

        foreach ($ids as $id) {
            DB::table('salons')->where('id', $id)->update([
                'booking_slug' => $this->generateUniqueSlug(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn('booking_slug');
        });
    }

    private function generateUniqueSlug(): string
    {
        do {
            $slug = strtolower(Str::random(16));
        } while (DB::table('salons')->where('booking_slug', $slug)->exists());

        return $slug;
    }
};
