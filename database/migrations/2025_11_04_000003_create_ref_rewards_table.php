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
        Schema::create('ref_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')->comment('获得奖励的用户ID');
            $table->foreignId('source_user_id')->nullable()->constrained('users')->onDelete('set null')->comment('奖励来源用户ID（如被推荐人）');
            $table->enum('type', [
                'referral_10pct',      // 首充推荐奖励10%
                'notifier_5pct',      // 通知人奖励5%
                'upline_5pct',        // 上级奖励5%（缺通知人时）
                'newbie_next_day',    // 新人次日奖励10%
                'ambassador_oneoff',   // 等级一次性奖励
                'dividend',            // 周期分红
            ])->comment('奖励类型');
            $table->decimal('amount', 36, 6)->comment('奖励金额');
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending')->comment('奖励状态');
            $table->foreignId('ref_event_id')->nullable()->constrained('ref_events')->onDelete('set null')->comment('关联的事件ID');
            $table->string('biz_id', 64)->nullable()->comment('业务ID（如deposit_id, withdrawal_id）用于幂等性');
            $table->timestamps();
            
            $table->index('user_id', 'idx_ref_rewards_user');
            $table->index('source_user_id', 'idx_ref_rewards_source_user');
            $table->index('type', 'idx_ref_rewards_type');
            $table->index('status', 'idx_ref_rewards_status');
            $table->index('ref_event_id', 'idx_ref_rewards_event');
            $table->index(['user_id', 'biz_id'], 'idx_ref_rewards_user_biz');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ref_rewards');
    }
};

