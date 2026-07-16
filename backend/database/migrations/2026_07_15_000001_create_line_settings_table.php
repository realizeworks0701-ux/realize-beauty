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
        Schema::create('line_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('channel_id');
            $table->text('channel_secret')->comment('暗号化保存（encrypted cast）');
            $table->text('channel_access_token')->comment('暗号化保存（encrypted cast）');
            $table->string('bot_user_id')->nullable()->unique()->comment('webhook の destination 照合キー');
            $table->string('bot_basic_id')->nullable()->comment('友だち追加URL用');
            $table->string('bot_display_name')->nullable();
            $table->boolean('is_active')->default(false)->comment('接続確認成功で true');
            $table->timestampTz('connected_at')->nullable();
            $table->timestampTz('last_webhook_at')->nullable()->comment('署名検証成功時のみ更新');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('line_settings');
    }
};
