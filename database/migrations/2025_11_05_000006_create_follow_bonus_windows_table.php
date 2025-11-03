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
        Schema::create('follow_bonus_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('reason', [
                'newbie_days2to6',      // 新人第2-6天
                'inviter_ratio30pct',   // 邀请人比例≥30%
            ])->comment('加餐原因');
            $table->date('start_date')->comment('开始日期');
            $table->date('end_date')->comment('结束日期');
            $table->unsignedInteger('daily_extra_quota')->default(4)->comment('每日额外配额');
            $table->timestamps();
            
            $table->index('user_id', 'idx_follow_bonus_windows_user');
            $table->index('start_date', 'idx_follow_bonus_windows_start_date');
            $table->index('end_date', 'idx_follow_bonus_windows_end_date');
            $table->index(['user_id', 'start_date', 'end_date'], 'idx_follow_bonus_windows_user_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_bonus_windows');
    }
};

