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
        Schema::create('ref_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');
            $table->unsignedInteger('direct_count')->default(0)->comment('直接邀请人数');
            $table->unsignedInteger('team_count')->default(0)->comment('团队总人数（含子树）');
            $table->enum('ambassador_level', ['L0', 'L1', 'L2', 'L3', 'L4', 'L5'])->default('L0')->comment('大使等级');
            $table->decimal('ambassador_reward_total', 36, 6)->default(0)->comment('等级一次性奖励总额');
            $table->decimal('dividend_rate', 5, 4)->default(0)->comment('分红比例（如0.0010表示0.1%）');
            $table->decimal('total_rewards', 36, 6)->default(0)->comment('累计获得奖励总额');
            $table->timestamps();
            
            $table->index('ambassador_level', 'idx_ambassador_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ref_stats');
    }
};

