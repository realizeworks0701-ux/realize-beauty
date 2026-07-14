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
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('menu_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->timestampTz('start_at');
            $table->timestampTz('end_at')->comment('start_at + menu.duration_minutes からサーバ導出');
            $table->string('status')->default('reserved')->comment('reserved/visited/cancelled/no_show');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['salon_id', 'start_at']);
            $table->index(['salon_id', 'user_id', 'start_at']);
            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
