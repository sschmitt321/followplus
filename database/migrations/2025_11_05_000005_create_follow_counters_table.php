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
        Schema::create('follow_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->date('date')->comment('日期');
            $table->unsignedInteger('base_quota_used')->default(0)->comment('基础配额已使用（每日2次）');
            $table->unsignedInteger('extra_quota_used')->default(0)->comment('加餐配额已使用（每日4次）');
            $table->timestamps();
            
            $table->unique(['user_id', 'date'], 'idx_follow_counters_user_date');
            $table->index('date', 'idx_follow_counters_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_counters');
    }
};

