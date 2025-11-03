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
        Schema::create('follow_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('follow_window_id')->constrained('follow_windows')->onDelete('cascade');
            $table->foreignId('symbol_id')->constrained('symbols')->onDelete('cascade');
            $table->decimal('amount_base', 36, 6)->comment('基础金额（总资产×1%）');
            $table->decimal('amount_input', 36, 6)->nullable()->comment('用户输入金额（仅审计用）');
            $table->enum('status', ['placed', 'expired', 'settled'])->default('placed')->comment('订单状态');
            $table->decimal('profit', 36, 6)->nullable()->comment('结算利润');
            $table->string('invite_token', 64)->nullable()->comment('使用的邀请码');
            $table->timestamp('settled_at')->nullable()->comment('结算时间');
            $table->timestamps();
            
            $table->index('user_id', 'idx_follow_orders_user');
            $table->index('follow_window_id', 'idx_follow_orders_window');
            $table->index('symbol_id', 'idx_follow_orders_symbol');
            $table->index('status', 'idx_follow_orders_status');
            $table->index('created_at', 'idx_follow_orders_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_orders');
    }
};

