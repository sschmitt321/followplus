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
        Schema::create('tron_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tron_address', 64)->comment('用户充值地址');
            $table->string('txid', 128)->comment('交易哈希');
            $table->string('from_address', 64)->comment('发送地址');
            $table->decimal('amount', 36, 6)->comment('充值金额');
            $table->string('token_symbol', 16)->default('USDT')->comment('代币符号');
            $table->unsignedInteger('confirmations')->default(0)->comment('确认数');
            $table->unsignedInteger('required_confirmations')->default(20)->comment('所需确认数');
            $table->enum('status', ['pending', 'confirmed', 'credited', 'failed'])->default('pending')->comment('状态');
            $table->timestamps();
            
            $table->unique(['txid', 'tron_address'], 'uniq_txid_token');
            $table->index('user_id');
            $table->index('tron_address');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tron_deposits');
    }
};

