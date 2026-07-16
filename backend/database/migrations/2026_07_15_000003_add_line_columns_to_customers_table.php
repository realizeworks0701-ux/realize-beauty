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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('line_user_id')->nullable()->comment('LINEユーザーID');
            $table->timestampTz('line_linked_at')->nullable()->comment('LINE連携完了日時');
            $table->string('line_link_code')->nullable()->comment('ワンタイム連携コード（6文字・単回使用）');
            $table->timestampTz('line_link_code_expires_at')->nullable()->comment('連携コード有効期限（発行から72時間）');
        });

        // 部分 unique index（NULL を許容しつつサロン内で一意にする）
        DB::statement(
            'CREATE UNIQUE INDEX customers_salon_id_line_user_id_unique
             ON customers (salon_id, line_user_id) WHERE line_user_id IS NOT NULL',
        );
        DB::statement(
            'CREATE UNIQUE INDEX customers_salon_id_line_link_code_unique
             ON customers (salon_id, line_link_code) WHERE line_link_code IS NOT NULL',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_salon_id_line_user_id_unique');
        DB::statement('DROP INDEX IF EXISTS customers_salon_id_line_link_code_unique');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'line_user_id',
                'line_linked_at',
                'line_link_code',
                'line_link_code_expires_at',
            ]);
        });
    }
};
