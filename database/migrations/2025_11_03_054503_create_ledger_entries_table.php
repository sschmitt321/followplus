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
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('currency', 10)->comment('币种');
            $table->decimal('amount', 36, 6)->comment('金额（正数为增加，负数为减少）');
            $table->decimal('balance_after', 36, 6)->comment('操作后余额');
            $table->string('biz_type', 50)->comment('业务类型：deposit, withdraw, transfer, swap, follow_settle, ref_reward 等');
            $table->unsignedBigInteger('ref_id')->nullable()->comment('关联业务ID');
            $table->json('meta_json')->nullable()->comment('元数据');
            $table->timestamp('created_at')->index();
            
            $table->index(['user_id', 'created_at']);
            $table->index(['account_id', 'created_at']);
            $table->index(['biz_type', 'ref_id']);
            $table->foreign('currency')->references('name')->on('currencies')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
