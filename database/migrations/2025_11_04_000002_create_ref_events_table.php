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
        Schema::create('ref_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trigger_user_id')->constrained('users')->onDelete('cascade')->comment('触发事件的用户ID');
            $table->enum('event_type', [
                'first_deposit',      // 首充
                'newbie_next_day',     // 新人次日奖励
                'ambassador_level_up', // 等级晋升
                'dividend',            // 分红派发
                'withdraw_paid',       // 提现完成（触发断链）
            ])->comment('事件类型');
            $table->decimal('amount', 36, 6)->nullable()->comment('事件金额（如首充金额）');
            $table->json('meta_json')->nullable()->comment('额外元数据');
            $table->timestamps();
            
            $table->index('trigger_user_id', 'idx_trigger_user');
            $table->index('event_type', 'idx_event_type');
            $table->index('created_at', 'idx_ref_events_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ref_events');
    }
};

