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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->string('kana');
            $table->tinyInteger('gender')->default(0)->comment('0:未回答 1:男性 2:女性 9:その他');
            $table->date('birthday')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('memo')->nullable();
            $table->date('first_visit_at')->nullable();
            $table->date('last_visit_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('salon_id');
            $table->index('phone');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
