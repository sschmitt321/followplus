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
        Schema::create('follow_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained('symbols')->onDelete('cascade');
            $table->enum('window_type', [
                'fixed_daily',      // 固定窗（13点、20点）
                'newbie_bonus',     // 新人加餐窗
                'inviter_bonus',    // 邀请人加餐窗
            ])->comment('窗口类型');
            $table->timestamp('start_at')->comment('开始时间');
            $table->timestamp('expire_at')->comment('过期时间');
            $table->decimal('reward_rate_min', 5, 4)->default(0.5)->comment('最小奖励率（如0.5表示50%）');
            $table->decimal('reward_rate_max', 5, 4)->default(0.6)->comment('最大奖励率（如0.6表示60%）');
            $table->enum('status', ['active', 'expired', 'settled'])->default('active')->comment('状态');
            $table->timestamps();
            
            $table->index('symbol_id', 'idx_follow_windows_symbol');
            $table->index('window_type', 'idx_follow_windows_window_type');
            $table->index('start_at', 'idx_follow_windows_start_at');
            $table->index('expire_at', 'idx_follow_windows_expire_at');
            $table->index('status', 'idx_follow_windows_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_windows');
    }
};

