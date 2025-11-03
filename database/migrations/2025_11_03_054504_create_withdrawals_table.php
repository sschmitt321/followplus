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
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('currency', 10)->comment('币种');
            $table->decimal('amount_request', 36, 6)->comment('申请金额');
            $table->decimal('fee', 36, 6)->default(0)->comment('手续费');
            $table->decimal('amount_actual', 36, 6)->comment('实际到账金额');
            $table->enum('status', ['pending', 'approved', 'rejected', 'paid', 'failed'])->default('pending');
            $table->string('to_address', 255)->comment('提现地址');
            $table->string('chain', 20)->nullable()->comment('链名称');
            $table->string('txid', 255)->nullable()->comment('交易哈希');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['user_id', 'created_at']);
            $table->index('status');
            $table->foreign('currency')->references('name')->on('currencies')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
